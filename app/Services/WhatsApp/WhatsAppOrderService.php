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
use App\Services\Erp\OrderWorkflowService;
use App\Services\Inventory\BranchStockService;
use App\Services\Inventory\SaleStockLocationResolver;
use App\Services\Inventory\StockUomDisplayService;
use App\Services\Sales\PosLinePricingService;
use App\Services\Sales\SaleLineQuantityDisplayService;
use App\Support\CustomerCreditLimit;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class WhatsAppOrderService
{
    public function __construct(
        protected StockUomDisplayService $stockUom,
        protected BranchStockService $branchStock,
        protected PosLinePricingService $pricing,
        protected SaleLineQuantityDisplayService $qtyDisplay,
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

    /**
     * @return array<int, array{
     *   product_code: string,
     *   quantity: float,
     *   product_name: string,
     *   display: string,
     *   line_total: float,
     *   unit_price: float,
     *   on_wholesale_retail: int,
     *   rw: string
     * }>
     */
    public function summarizeSaleLines(Sale $sale): array
    {
        $lines = [];
        foreach ($sale->items as $item) {
            $product = $item->product;
            $qty = (float) $item->quantity;
            $isRetail = (bool) ($item->on_wholesale_retail ?? false)
                && (bool) ($product?->sell_on_retail);
            $display = $product
                ? $this->qtyDisplay->formatLineQtyDisplay($qty, $product, $isRetail)
                : $this->stockUom->formatMixedStockDisplay($qty, $product?->unit)['text'];
            $lineTotal = (float) $item->amount;
            $unitPrice = $product
                ? $this->qtyDisplay->displayUnitPrice(
                    $qty,
                    $lineTotal,
                    $product,
                    $isRetail,
                    (float) ($item->discount_given ?? 0),
                    null,
                    isset($item->display_unit_price) ? (float) $item->display_unit_price : null,
                )
                : (float) ($item->selling_price ?? 0);

            $lines[] = [
                'product_code' => (string) $item->product_code,
                'quantity' => $qty,
                'product_name' => $product?->product_name ?? (string) $item->product_code,
                'display' => $display,
                'line_total' => $lineTotal,
                'unit_price' => $unitPrice,
                'on_wholesale_retail' => $isRetail ? 1 : 0,
                'rw' => $isRetail ? 'R' : 'W',
            ];
        }

        return $lines;
    }

    public function formatMoney(float $amount): string
    {
        return 'KES '.number_format($amount, 0, '.', ',');
    }

    /** Format: Product Name, unit price, qty, amount, R/W */
    public function formatSummaryLine(array $line): string
    {
        $name = (string) ($line['product_name'] ?? $line['product_code'] ?? 'Item');
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $qty = (string) ($line['display'] ?? $line['quantity'] ?? '');
        $amount = (float) ($line['line_total'] ?? 0);
        $rw = strtoupper((string) ($line['rw'] ?? ((($line['on_wholesale_retail'] ?? 0) ? 'R' : 'W'))));

        return "{$name}, ".$this->formatMoney($unitPrice).", {$qty}, ".$this->formatMoney($amount).", {$rw}";
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
     * @param  array<int, array{product_code: string, quantity: float, on_wholesale_retail?: int|bool}>  $lines
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
        $orgId = (int) $customer->organization_id;

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
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->whereIn('product_code', $productCodes)
                ->pluck('product_code')
                ->all();

        $validCodeSet = array_fill_keys($validCodes, true);

        $cartReq = AiFormRequestHelper::prepare(
            StoreCartRequest::create('/sales/carts', 'POST', [
                'channel' => 'whatsapp',
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

            $onWholesaleRetail = ! empty($line['on_wholesale_retail']) ? 1 : 0;

            $lineReq = AiFormRequestHelper::prepare(
                AddCartLineRequest::create("/sales/carts/{$cartId}/lines", 'POST', [
                    'product_code' => $productCode,
                    'quantity' => $qty,
                    'on_wholesale_retail' => $onWholesaleRetail,
                ]),
                $botUser,
            );
            app(CartOperationsController::class)->addLine($lineReq, $cartId);
        }

        $org = $botUser->organization;
        $gate = (new CapabilityGate)->forOrganization($org);
        $workflow = OrderWorkflowService::forGate($gate);
        // Cart channel is whatsapp; workflow normalizes to backend (same save status as backoffice).
        $saveStatus = $workflow->resolveSaveStatus('whatsapp');

        $checkoutPayload = [
            'customer_num' => $customer->customer_num,
            'save_only' => true,
            'pay_now' => 0,
            'is_credit_sale' => $isCredit,
            // Match backoffice "Save order": workflow/inventory timing decides deduct vs reserve.
            'deduct_stock' => true,
            'status' => $saveStatus,
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
        $branchId = $customer->branch_id
            ? (int) $customer->branch_id
            : ($botUser->branch_id && (int) $botUser->organization_id === (int) $customer->organization_id
                ? (int) $botUser->branch_id
                : null);
        $inventory = $gate->moduleSettings('inventory');
        $sales = $gate->moduleSettings('sales');
        $splitShopStore = ! empty($sales['retail_shop_wholesale_store_stock']);
        $routeId = $customer->route_id ? (int) $customer->route_id : null;
        $orgId = (int) $customer->organization_id;
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
                ->where('organization_id', $orgId)
                ->where('product_code', $code)
                ->whereNull('deleted_at')
                ->first();

            if (! $product) {
                $warnings[] = "Product {$code} is no longer available.";

                continue;
            }

            $isRetail = (bool) ($product->sell_on_retail)
                && ! empty($line['on_wholesale_retail']);
            $lineTotal = $this->pricing->lineTotalBeforeDiscount(
                $product,
                $baseQty,
                $isRetail,
                $routeId,
                $orgId,
            );
            $unitPrice = $this->qtyDisplay->displayUnitPrice(
                $baseQty,
                $lineTotal,
                $product,
                $isRetail,
            );
            $display = $line['display']
                ?? $this->qtyDisplay->formatLineQtyDisplay($baseQty, $product, $isRetail);

            $location = SaleStockLocationResolver::forLine(
                'whatsapp',
                $inventory,
                $sales,
                $product,
                $isRetail,
            );

            $available = $this->availableQtyForLocation(
                $product,
                $branchId,
                $orgId,
                $location,
                $splitShopStore,
            );

            if ($available + 0.0001 < $baseQty) {
                $locLabel = $location === 'store' ? 'store' : 'shop';
                $warnings[] = "{$product->product_name} ({$locLabel}): only "
                    .$this->stockUom->formatMixedStockDisplay($available, $product->unit)['text']
                    .' available.';
            }

            $lines[] = [
                'product_code' => $code,
                'product_name' => (string) $product->product_name,
                'quantity' => $baseQty,
                'display' => $display,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'on_wholesale_retail' => $isRetail ? 1 : 0,
                'rw' => $isRetail ? 'R' : 'W',
                'stock_location' => $location,
            ];
            $total += $lineTotal;
        }

        return [
            'lines' => $lines,
            'estimated_total' => round($total, 2),
            'stock_warnings' => $warnings,
        ];
    }

    /**
     * Sellable quantity at the location WhatsApp will draw from for this line.
     */
    protected function availableQtyForLocation(
        Product $product,
        ?int $branchId,
        int $orgId,
        string $location,
        bool $splitShopStore,
    ): float {
        $location = $location === 'store' ? 'store' : 'shop';

        if ($branchId) {
            $payload = $this->branchStock->overlayPayload($product->toArray(), $branchId);
            $payload = $this->branchStock->applySalesConsumerStock(
                $payload,
                $location,
                $splitShopStore,
            );
            $availableKey = $location === 'store' ? 'stock_available_store' : 'stock_available_shop';
            $onHandKey = $location === 'store' ? 'stock_in_store' : 'stock_in_shop';

            return (float) ($payload[$availableKey] ?? $payload[$onHandKey] ?? 0);
        }

        // No branch (rare): sum live branch stock for this org at the sale location.
        $column = $location === 'store' ? 'store_quantity' : 'shop_quantity';
        $qty = (float) \Illuminate\Support\Facades\DB::table('current_stock as cs')
            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
            ->where('b.organization_id', $orgId)
            ->where('cs.product_code', $product->product_code)
            ->selectRaw("COALESCE(SUM(COALESCE(cs.{$column}, 0)), 0) as qty")
            ->value('qty');

        if ($qty > 0) {
            return $qty;
        }

        // Fall back to denormalized product columns.
        return $location === 'store'
            ? (float) ($product->stock_in_store ?? 0)
            : (float) ($product->stock_in_shop ?? 0);
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
