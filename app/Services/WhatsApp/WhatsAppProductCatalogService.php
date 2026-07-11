<?php

namespace App\Services\WhatsApp;

use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Inventory\BranchStockService;
use App\Services\Inventory\SaleStockLocationResolver;
use App\Services\Inventory\StockUomDisplayService;
use App\Support\SqlLikeSearch;
use Illuminate\Database\Eloquent\Builder;

class WhatsAppProductCatalogService
{
    public const PER_PAGE = 8;

    /** Larger page for platform-admin dry-run product preview. */
    public const PLATFORM_PREVIEW_PER_PAGE = 40;

    public function __construct(
        protected BranchStockService $branchStock,
        protected StockUomDisplayService $stockUom,
    ) {}

    public function resolveBranchId(Customer $customer, User $botUser): ?int
    {
        // Platform-admin dry-run stand-in (different org) — use org-wide stock, not a guessed branch.
        if ((int) $botUser->organization_id !== (int) $customer->organization_id) {
            return null;
        }

        if ($customer->branch_id) {
            return (int) $customer->branch_id;
        }

        return $botUser->branch_id ? (int) $botUser->branch_id : null;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool}
     */
    public function searchInStock(
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        string $term,
        int $page = 1,
        ?int $perPage = null,
    ): array {
        $term = trim($term);
        if ($term === '') {
            return $this->emptyPage($page, $perPage);
        }

        $query = $this->inStockQuery($customer, $botUser, $gate);
        SqlLikeSearch::applyProductSearch($query, $term, 'products.product_code', 'products.product_name');

        return $this->paginateProducts($query, $customer, $botUser, $gate, $page, $perPage);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool}
     */
    public function browseInStock(
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        int $page = 1,
        ?int $perPage = null,
    ): array {
        $query = $this->inStockQuery($customer, $botUser, $gate)->orderBy('products.product_name');

        return $this->paginateProducts($query, $customer, $botUser, $gate, $page, $perPage);
    }

    /** @return array<string, mixed>|null */
    public function findInStockByCode(
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        string $productCode,
    ): ?array {
        $code = trim($productCode);
        if ($code === '') {
            return null;
        }

        $product = $this->inStockQuery($customer, $botUser, $gate)
            ->where('products.product_code', $code)
            ->with('unit')
            ->first();

        return $product ? $this->mapProduct($product, $customer, $botUser, $gate) : null;
    }

    /** @return Builder<Product> */
    protected function inStockQuery(Customer $customer, User $botUser, CapabilityGate $gate): Builder
    {
        // Cross-org bot (platform admin dry-run): include products with stock on the product
        // row or at any branch — don't hide catalog behind a single customer/bot branch.
        if ((int) $botUser->organization_id !== (int) $customer->organization_id) {
            return $this->platformPreviewStockQuery((int) $customer->organization_id);
        }

        $branchId = $this->resolveBranchId($customer, $botUser);
        $inventory = $gate->moduleSettings('inventory');
        $sales = $gate->moduleSettings('sales');

        $query = Product::query()
            ->where('products.organization_id', $customer->organization_id)
            ->whereNull('products.deleted_at');

        $this->branchStock->applyConsumerAvailableStockFilter(
            $query,
            $branchId,
            'backend',
            $inventory,
            $sales,
        );

        return $query->with('unit');
    }

    /** @return Builder<Product> */
    protected function platformPreviewStockQuery(int $organizationId): Builder
    {
        return Product::query()
            ->where('products.organization_id', $organizationId)
            ->whereNull('products.deleted_at')
            ->where(function ($outer) use ($organizationId) {
                $outer->whereRaw('(COALESCE(products.stock_in_shop, 0) + COALESCE(products.stock_in_store, 0)) > 0')
                    ->orWhereExists(function ($sub) use ($organizationId) {
                        $sub->selectRaw('1')
                            ->from('current_stock as cs')
                            ->join('branches as b', 'b.id', '=', 'cs.branch_id')
                            ->whereColumn('cs.product_code', 'products.product_code')
                            ->where('b.organization_id', $organizationId)
                            ->whereRaw('(COALESCE(cs.shop_quantity, 0) + COALESCE(cs.store_quantity, 0)) > 0');
                    });
            })
            ->with('unit');
    }

    /**
     * @param  Builder<Product>  $query
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool}
     */
    protected function paginateProducts(
        Builder $query,
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        int $page,
        ?int $perPage = null,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage ?? self::PER_PAGE));
        $total = (clone $query)->count();
        $products = $query
            ->orderBy('products.product_name')
            ->forPage($page, $perPage)
            ->get();

        $items = $products
            ->map(fn (Product $product) => $this->mapProduct($product, $customer, $botUser, $gate))
            ->values()
            ->all();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => ($page * $perPage) < $total,
        ];
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool} */
    protected function emptyPage(int $page, ?int $perPage = null): array
    {
        return [
            'items' => [],
            'total' => 0,
            'page' => max(1, $page),
            'per_page' => max(1, min(100, $perPage ?? self::PER_PAGE)),
            'has_more' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function mapProduct(
        Product $product,
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
    ): array {
        $crossOrgPreview = (int) $botUser->organization_id !== (int) $customer->organization_id;
        $branchId = $this->resolveBranchId($customer, $botUser);
        $payload = $product->toArray();

        if ($branchId) {
            $payload = $this->branchStock->overlayPayload($payload, $branchId);
            $payload = $this->branchStock->applySalesConsumerStock(
                $payload,
                SaleStockLocationResolver::forCatalogList(
                    'backend',
                    $gate->moduleSettings('inventory'),
                    $gate->moduleSettings('sales'),
                ),
                ! empty($gate->moduleSettings('sales')['retail_shop_wholesale_store_stock']),
            );
        }

        $available = (float) ($payload['stock_in_shop'] ?? 0) + (float) ($payload['stock_in_store'] ?? 0);
        if ($branchId) {
            $available = max(
                (float) ($payload['stock_available_shop'] ?? 0),
                (float) ($payload['stock_available_store'] ?? 0),
            );
            if (! empty($gate->moduleSettings('sales')['retail_shop_wholesale_store_stock'])) {
                $available = (float) ($payload['stock_available_shop'] ?? 0)
                    + (float) ($payload['stock_available_store'] ?? 0);
            }
        } elseif ($crossOrgPreview) {
            $branchTotal = (float) \Illuminate\Support\Facades\DB::table('current_stock as cs')
                ->join('branches as b', 'b.id', '=', 'cs.branch_id')
                ->where('b.organization_id', $customer->organization_id)
                ->where('cs.product_code', $product->product_code)
                ->selectRaw('COALESCE(SUM(COALESCE(cs.shop_quantity, 0) + COALESCE(cs.store_quantity, 0)), 0) as qty')
                ->value('qty');
            $available = max($available, $branchTotal);
        }

        $uom = $product->relationLoaded('unit') ? $product->unit : $product->unit()->first();
        if ($uom && ! $uom instanceof \App\Models\Uom) {
            $uom = null;
        }
        $displayStock = $this->stockUom->formatMixedStockDisplay($available, $uom)['text'];

        return [
            'product_code' => (string) $product->product_code,
            'product_name' => (string) $product->product_name,
            'unit_price' => (float) $product->unit_price,
            'available_qty' => $available,
            'available_display' => $displayStock,
            'uom_snapshot' => $uom ? $uom->only([
                'conversion_factor',
                'full_name',
                'small_packaging_label',
                'middle_packaging_label',
                'middle_factor',
                'uses_small_packaging',
                'uom_type',
            ]) : null,
        ];
    }

    /**
     * @return array{qty: float|null, term: string}|null
     */
    public function parseProductQuery(string $input): ?array
    {
        $raw = trim($input);
        if ($raw === '' || strlen($raw) < 2) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*(?:x|×)?\s*(.+)$/iu', $raw, $matches)) {
            return [
                'qty' => max(0.0, (float) $matches[1]),
                'term' => trim($matches[2]),
            ];
        }

        if (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)$/iu', $raw, $matches)) {
            return [
                'qty' => max(0.0, (float) $matches[2]),
                'term' => trim($matches[1]),
            ];
        }

        return [
            'qty' => null,
            'term' => $raw,
        ];
    }
}
