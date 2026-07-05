<?php

namespace App\Services\WhatsApp;

use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Http\Controllers\Api\V1\Operations\CheckoutController;
use App\Http\Requests\Sales\AddCartLineRequest;
use App\Http\Requests\Sales\CheckoutRequest;
use App\Http\Requests\Sales\StoreCartRequest;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\Ai\AiFormRequestHelper;
use App\Services\Erp\CapabilityGate;
use App\Services\Inventory\BranchStockService;
use App\Services\Inventory\SaleStockLocationResolver;
use App\Services\Inventory\StockUomDisplayService;
use App\Support\CustomerCreditLimit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class WhatsAppOrderService
{
    public function __construct(
        protected StockUomDisplayService $stockUom,
        protected BranchStockService $branchStock,
    ) {}

    public function lastSaleForCustomer(Customer $customer): ?Sale
    {
        return Sale::query()
            ->with(['items.product.unit'])
            ->where('customer_num', $customer->customer_num)
            ->where('organization_id', $customer->organization_id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'held'])
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, array{product_code: string, product_name: string, unit_price: float, uom: mixed}> */
    public function quickListProducts(Customer $customer, int $limit = 8): Collection
    {
        $sales = Sale::query()
            ->with(['items.product.unit'])
            ->where('customer_num', $customer->customer_num)
            ->where('organization_id', $customer->organization_id)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled'])
            ->orderByDesc('id')
            ->limit(3)
            ->get();

        $seen = [];
        $products = collect();

        foreach ($sales as $sale) {
            foreach ($sale->items as $item) {
                $code = (string) $item->product_code;
                if ($code === '' || isset($seen[$code])) {
                    continue;
                }
                $seen[$code] = true;
                $product = $item->product;
                $products->push([
                    'product_code' => $code,
                    'product_name' => $product?->product_name ?? $code,
                    'unit_price' => (float) ($item->selling_price ?? $product?->unit_price ?? 0),
                    'uom' => $product?->unit,
                ]);
                if ($products->count() >= $limit) {
                    return $products;
                }
            }
        }

        return $products;
    }

    /** @return array<int, array{product_code: string, quantity: float, product_name: string, display: string, line_total: float}> */
    public function summarizeSaleLines(Sale $sale): array
    {
        $lines = [];
        foreach ($sale->items as $item) {
            $product = $item->product;
            $qty = (float) $item->quantity;
            $display = $this->stockUom->formatMixedStockDisplay($qty, $product?->unit)['text'];
            $lines[] = [
                'product_code' => (string) $item->product_code,
                'quantity' => $qty,
                'product_name' => $product?->product_name ?? (string) $item->product_code,
                'display' => $display,
                'line_total' => (float) $item->amount,
            ];
        }

        return $lines;
    }

    public function formatMoney(float $amount): string
    {
        return 'KES '.number_format($amount, 0, '.', ',');
    }

    public function creditAvailable(Customer $customer): ?float
    {
        $limit = (float) $customer->credit_limit;
        if ($limit <= 0) {
            return null;
        }

        $outstanding = CustomerCreditLimit::displayOutstanding($customer);

        return max(0, $limit - $outstanding);
    }

    /**
     * @param  array<int, array{product_code: string, quantity: float}>  $lines
     * @return array{order_num: int|null, sale_id: int|null, status: string|null, order_total: float|null}
     */
    public function placeOrder(
        User $botUser,
        Customer $customer,
        array $lines,
        ?string $comments = null,
        bool $useCredit = true,
    ): array {
        if ($lines === []) {
            throw new InvalidArgumentException('Order has no lines.');
        }

        $isCredit = $useCredit && (float) $customer->credit_limit > 0;

        $productCodes = collect($lines)
            ->pluck('product_code')
            ->map(fn ($code) => (string) $code)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $validCodes = $productCodes === []
            ? []
            : Product::query()
                ->where('organization_id', $botUser->organization_id)
                ->whereNull('deleted_at')
                ->whereIn('product_code', $productCodes)
                ->pluck('product_code')
                ->all();

        $validCodeSet = array_fill_keys($validCodes, true);

        $cartReq = AiFormRequestHelper::prepare(
            StoreCartRequest::create('/sales/carts', 'POST', [
                'channel' => 'backend',
                'order_source' => 'whatsapp',
                'branch_id' => $customer->branch_id ?? $botUser->branch_id,
                'route_id' => $customer->route_id,
            ]),
            $botUser,
        );
        $cart = app(CartOperationsController::class)->store($cartReq)->getData(true);
        $cartId = (int) ($cart['id'] ?? 0);

        foreach ($lines as $line) {
            $productCode = (string) ($line['product_code'] ?? '');
            $qty = (float) ($line['quantity'] ?? 0);
            if ($productCode === '' || $qty <= 0) {
                continue;
            }

            if (! isset($validCodeSet[$productCode])) {
                throw new InvalidArgumentException("Product [{$productCode}] not found.");
            }

            $lineReq = AiFormRequestHelper::prepare(
                AddCartLineRequest::create("/sales/carts/{$cartId}/lines", 'POST', [
                    'product_code' => $productCode,
                    'quantity' => $qty,
                ]),
                $botUser,
            );
            app(CartOperationsController::class)->addLine($lineReq, $cartId);
        }

        $checkoutPayload = [
            'customer_num' => $customer->customer_num,
            'save_only' => true,
            'pay_now' => 0,
            'is_credit_sale' => $isCredit,
            'deduct_stock' => false,
        ];

        if ($comments !== null && trim($comments) !== '') {
            $checkoutPayload['comments'] = trim($comments);
        }

        $checkoutReq = AiFormRequestHelper::prepare(
            CheckoutRequest::create("/sales/carts/{$cartId}/checkout", 'POST', $checkoutPayload),
            $botUser,
        );

        $response = app(CheckoutController::class)->fromCart($checkoutReq, $cartId);
        $sale = json_decode($response->getContent(), true) ?? [];

        if (! empty($comments) && ! empty($sale['id'])) {
            Sale::query()->where('id', (int) $sale['id'])->update([
                'comments' => trim($comments),
            ]);
        }

        return [
            'order_num' => isset($sale['order_num']) ? (int) $sale['order_num'] : null,
            'sale_id' => isset($sale['id']) ? (int) $sale['id'] : null,
            'status' => $sale['status'] ?? null,
            'order_total' => isset($sale['order_total']) ? (float) $sale['order_total'] : null,
        ];
    }

    /** @return array<int, array{order_num: int, status: string, total: float, created_at: string}> */
    public function recentOrders(Customer $customer, int $limit = 5): array
    {
        return Sale::query()
            ->where('customer_num', $customer->customer_num)
            ->where('organization_id', $customer->organization_id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (Sale $sale) => [
                'order_num' => (int) $sale->order_num,
                'status' => (string) $sale->status,
                'total' => (float) $sale->order_total,
                'created_at' => optional($sale->created_at)->toDateString() ?? '',
            ])
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $cartLines
     * @return array{
     *   lines: list<array<string, mixed>>,
     *   estimated_total: float,
     *   stock_warnings: list<string>
     * }
     */
    public function previewCart(
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        array $cartLines,
    ): array {
        $branchId = $customer->branch_id ? (int) $customer->branch_id : ($botUser->branch_id ? (int) $botUser->branch_id : null);
        $inventory = $gate->moduleSettings('inventory');
        $sales = $gate->moduleSettings('sales');
        $lines = [];
        $total = 0.0;
        $warnings = [];

        foreach ($cartLines as $line) {
            $code = (string) ($line['product_code'] ?? '');
            $baseQty = (float) ($line['quantity'] ?? 0);
            if ($code === '' || $baseQty <= 0) {
                continue;
            }

            $product = Product::query()
                ->with('unit')
                ->where('organization_id', $customer->organization_id)
                ->where('product_code', $code)
                ->whereNull('deleted_at')
                ->first();

            if (! $product) {
                $warnings[] = "Product {$code} is no longer available.";

                continue;
            }

            $unitPrice = (float) $product->unit_price;
            $lineTotal = round($unitPrice * $baseQty, 2);
            $display = $line['display'] ?? $this->stockUom->formatMixedStockDisplay($baseQty, $product->unit)['text'];
            $payload = $product->toArray();
            if ($branchId) {
                $payload = $this->branchStock->overlayPayload($payload, $branchId);
                $payload = $this->branchStock->applySalesConsumerStock(
                    $payload,
                    SaleStockLocationResolver::forLine(
                        'backend',
                        $inventory,
                        $sales,
                        $product,
                        (bool) $product->sell_on_retail,
                    ),
                    ! empty($sales['retail_shop_wholesale_store_stock']),
                );
            }

            $available = (float) ($payload['stock_in_shop'] ?? 0);
            if ($branchId && isset($payload['stock_available_shop'])) {
                $available = (float) $payload['stock_available_shop'];
            }

            if ($available < $baseQty) {
                $warnings[] = "{$product->product_name}: only ".$this->stockUom->formatMixedStockDisplay($available, $product->unit)['text'].' in stock.';
            }

            $lines[] = [
                'product_code' => $code,
                'product_name' => (string) $product->product_name,
                'quantity' => $baseQty,
                'display' => $display,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
            $total += $lineTotal;
        }

        return [
            'lines' => $lines,
            'estimated_total' => round($total, 2),
            'stock_warnings' => $warnings,
        ];
    }

    public function logOrderFailure(
        int $organizationId,
        ?int $conversationId,
        string $phone,
        string $error,
        array $cartLines = [],
    ): void {
        \App\Models\WhatsappMessageLog::query()->create([
            'organization_id' => $organizationId,
            'conversation_id' => $conversationId,
            'direction' => 'system',
            'from_phone' => $phone,
            'body' => $error,
            'meta' => [
                'event' => 'order_failed',
                'error' => $error,
                'cart' => $cartLines,
            ],
            'created_at' => now(),
        ]);
    }
}
