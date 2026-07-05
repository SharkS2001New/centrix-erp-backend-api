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

    public function __construct(
        protected BranchStockService $branchStock,
        protected StockUomDisplayService $stockUom,
    ) {}

    public function resolveBranchId(Customer $customer, User $botUser): ?int
    {
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
    ): array {
        $term = trim($term);
        if (strlen($term) < 2) {
            return $this->emptyPage($page);
        }

        $query = $this->inStockQuery($customer, $botUser, $gate);
        SqlLikeSearch::applyProductSearch($query, $term, 'products.product_code', 'products.product_name');

        return $this->paginateProducts($query, $customer, $botUser, $gate, $page);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, has_more: bool}
     */
    public function browseInStock(
        Customer $customer,
        User $botUser,
        CapabilityGate $gate,
        int $page = 1,
    ): array {
        $query = $this->inStockQuery($customer, $botUser, $gate)->orderBy('products.product_name');

        return $this->paginateProducts($query, $customer, $botUser, $gate, $page);
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
    ): array {
        $page = max(1, $page);
        $perPage = self::PER_PAGE;
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
    protected function emptyPage(int $page): array
    {
        return [
            'items' => [],
            'total' => 0,
            'page' => max(1, $page),
            'per_page' => self::PER_PAGE,
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
        }

        $uom = $product->unit;
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
