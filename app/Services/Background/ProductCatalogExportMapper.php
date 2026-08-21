<?php

namespace App\Services\Background;

use App\Models\Category;
use App\Models\SubCategory;
use App\Models\Supplier;
use App\Models\Uom;
use App\Models\Vat;
use App\Services\Inventory\StockUomDisplayService;
use Illuminate\Support\Collection;

/**
 * Map raw product API rows to product-catalog export column keys.
 */
class ProductCatalogExportMapper implements ListExportRowMapper
{
    public function __construct(
        protected StockUomDisplayService $stockUom,
        protected ExportInventoryQtyFormatter $qtyFormatter,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $products): array
    {
        if ($products === []) {
            return [];
        }

        $lookups = $this->loadLookups($products);

        return array_map(
            fn (array $product) => $this->mapOne($product, $lookups),
            $products,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{
     *     subs: Collection<int|string, SubCategory>,
     *     cats: Collection<int|string, Category>,
     *     suppliers: Collection<int|string, Supplier>,
     *     vats: Collection<int|string, Vat>,
     *     uoms: Collection<int|string, Uom>
     * }
     */
    protected function loadLookups(array $products): array
    {
        $subIds = $this->collectIds($products, 'subcategory_id');
        $supplierIds = $this->collectIds($products, 'supplier_id');
        $vatIds = $this->collectIds($products, 'vat_id');
        $unitIds = $this->collectIds($products, 'unit_id');

        $subs = $subIds === []
            ? collect()
            : SubCategory::query()->whereIn('id', $subIds)->get()->keyBy('id');

        $catIds = $subs->pluck('category_id')->filter()->unique()->values()->all();
        $cats = $catIds === []
            ? collect()
            : Category::query()->whereIn('id', $catIds)->get()->keyBy('id');

        return [
            'subs' => $subs,
            'cats' => $cats,
            'suppliers' => $supplierIds === []
                ? collect()
                : Supplier::query()->whereIn('id', $supplierIds)->get()->keyBy('id'),
            'vats' => $vatIds === []
                ? collect()
                : Vat::query()->whereIn('id', $vatIds)->get()->keyBy('id'),
            'uoms' => $unitIds === []
                ? collect()
                : Uom::query()->whereIn('id', $unitIds)->get()->keyBy('id'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<int>
     */
    protected function collectIds(array $products, string $field): array
    {
        $ids = [];
        foreach ($products as $product) {
            $id = $product[$field] ?? null;
            if ($id !== null && $id !== '') {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array{
     *     subs: Collection<int|string, SubCategory>,
     *     cats: Collection<int|string, Category>,
     *     suppliers: Collection<int|string, Supplier>,
     *     vats: Collection<int|string, Vat>,
     *     uoms: Collection<int|string, Uom>
     * }  $lookups
     * @return array<string, mixed>
     */
    protected function mapOne(array $product, array $lookups): array
    {
        $sub = $lookups['subs']->get($product['subcategory_id'] ?? null);
        $cat = $sub ? $lookups['cats']->get($sub->category_id) : null;
        $supplier = $lookups['suppliers']->get($product['supplier_id'] ?? null);
        $vat = $lookups['vats']->get($product['vat_id'] ?? null);
        $uom = $lookups['uoms']->get($product['unit_id'] ?? null);

        $discount = ($product['discount_type'] ?? '') === 'fixed'
            ? ($product['discount_value'] ?? '')
            : ($product['discount_percentage'] ?? '');

        // Match catalogue UI (enrichProduct): available first, then on-hand fallback.
        $shopBase = $product['stock_available_shop']
            ?? $product['shop_qty']
            ?? $product['stock_in_shop']
            ?? null;
        $storeBase = $product['stock_available_store']
            ?? $product['store_qty']
            ?? $product['stock_in_store']
            ?? null;

        $isActive = array_key_exists('is_active', $product)
            ? ! empty($product['is_active'])
            : empty($product['deleted_at']);

        $sellOnRetail = $product['sell_on_retail'] ?? false;

        return [
            'product_code' => $product['product_code'] ?? '',
            'product_name' => $product['product_name'] ?? '',
            'category_name' => $product['category_name'] ?? ($cat?->category_name ?? 'Uncategorised'),
            'subcategory_name' => $product['subcategory_name'] ?? ($sub?->subcategory_name ?? 'General'),
            'unit_price' => $product['unit_price'] ?? '',
            'last_cost_price' => $product['last_cost_price'] ?? '',
            'discount' => $discount,
            'shop_qty' => $this->formatStockQty($shopBase, $uom, $product),
            'store_qty' => $this->formatStockQty($storeBase, $uom, $product),
            'uom_label' => $product['uom_label'] ?? ($uom?->full_name ?? '—'),
            'supplier_name' => $product['supplier_name'] ?? ($supplier?->supplier_name ?? '—'),
            'vat_treatment' => $product['vat_treatment'] ?? $this->vatTreatmentLabel($vat),
            'pricing' => $product['pricing'] ?? $this->pricingLabel($sellOnRetail),
            'is_active' => $isActive ? 'Yes' : 'No',
            // Keep UOM metadata so ReportExportService can re-format if needed.
            'conversion_factor' => $uom?->conversion_factor ?? ($product['conversion_factor'] ?? 1),
            'uom_name' => $uom?->full_name ?? ($product['uom_name'] ?? null),
            'small_packaging_label' => $uom?->small_packaging_label ?? ($product['small_packaging_label'] ?? null),
            'middle_packaging_label' => $uom?->middle_packaging_label ?? ($product['middle_packaging_label'] ?? null),
            'middle_factor' => $uom?->middle_factor ?? ($product['middle_factor'] ?? null),
            'uom_type' => $uom?->uom_type ?? ($product['uom_type'] ?? null),
            'uses_small_packaging' => $uom?->uses_small_packaging ?? ($product['uses_small_packaging'] ?? true),
        ];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    protected function formatStockQty(mixed $baseQty, ?Uom $uom, array $product): string
    {
        if ($baseQty === null || $baseQty === '') {
            return '';
        }
        if (is_string($baseQty) && ! is_numeric(trim($baseQty))) {
            return $baseQty;
        }
        if (! is_numeric($baseQty)) {
            return (string) $baseQty;
        }

        $resolved = $uom;
        if ($resolved === null && $this->productHasUomPackagingMeta($product)) {
            $resolved = $this->qtyFormatter->resolveUom($product);
        }

        if ($resolved === null) {
            return (string) $baseQty;
        }

        return $this->stockUom->formatMixedStockDisplay((float) $baseQty, $resolved)['text'];
    }

    /**
     * @param  array<string, mixed>  $product
     */
    protected function productHasUomPackagingMeta(array $product): bool
    {
        return array_key_exists('conversion_factor', $product)
            || array_key_exists('uom_factor', $product)
            || array_key_exists('small_packaging_label', $product)
            || isset($product['uom'])
            || isset($product['product_uom']);
    }

    protected function vatTreatmentLabel(?Vat $vat): string
    {
        if ($vat === null) {
            return '—';
        }

        $code = strtoupper((string) ($vat->vat_code ?? ''));

        return $code === 'V' ? 'Vatable' : 'Invatable';
    }

    protected function pricingLabel(mixed $sellOnRetail): string
    {
        return filter_var($sellOnRetail, FILTER_VALIDATE_BOOLEAN) ? 'Sells W/R' : 'Wholesale';
    }
}
