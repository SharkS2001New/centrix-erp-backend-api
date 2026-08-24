<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\Product;
use App\Models\RetailPackageSetting;
use App\Models\Uom;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockCostCalculation;
use App\Services\Inventory\StockUomDisplayService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Product catalogue details for AI: UoM hierarchy, stock labels, retail packaging.
 */
class GetProductDetailsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected StockUomDisplayService $stockUom,
    ) {}

    public function name(): string
    {
        return 'get_product_details';
    }

    public function description(): string
    {
        return 'Look up a Centrix product by SKU/code or name and return measurements (UoM hierarchy: '
            .'full pack / middle / small base units), current stock with qty_label, sell-on-retail flag, '
            .'and retail packaging / pricing tiers. Use for questions like "is this in kg or bags?", '
            .'"how is retail packaging set?", "what is the conversion factor?", or product measurement explainers.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_code' => [
                    'type' => 'string',
                    'description' => 'Exact product SKU / product_code when known.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Product name or partial SKU search when product_code is unknown.',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $orgId = (int) $organization->id;
        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'catalogue.products.view', $gate)
            || $this->permissions->hasPermission($user, 'inventory.stock.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.pos', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view product catalogue data.',
                'screens' => [
                    ['label' => 'Products', 'path' => '/products'],
                    ['label' => 'Units of measure', 'path' => '/uoms'],
                ],
            ];
        }

        $code = trim((string) ($arguments['product_code'] ?? ''));
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($code === '' && $query === '') {
            return [
                'error' => true,
                'message' => 'Provide product_code or query (product name / SKU).',
                'screens' => [
                    ['label' => 'Products', 'path' => '/products'],
                ],
            ];
        }

        $product = $this->findProduct($orgId, $code, $query);
        if (! $product) {
            return [
                'error' => true,
                'message' => 'No matching product found.',
                'searched' => ['product_code' => $code !== '' ? $code : null, 'query' => $query !== '' ? $query : null],
                'screens' => [
                    ['label' => 'Products', 'path' => '/products'],
                    ['label' => 'Units of measure', 'path' => '/uoms'],
                ],
            ];
        }

        $uom = null;
        if ($product->unit_id && Schema::hasTable('uoms')) {
            $uom = Uom::query()->find((int) $product->unit_id);
        }

        $shop = (float) ($product->stock_in_shop ?? 0);
        $store = (float) ($product->stock_in_store ?? 0);
        $total = $shop + $store;
        $shopLabel = $this->stockUom->formatMixedStockDisplay($shop, $uom)['text'];
        $storeLabel = $this->stockUom->formatMixedStockDisplay($store, $uom)['text'];
        $totalLabel = $this->stockUom->formatMixedStockDisplay($total, $uom)['text'];

        $measurements = $this->buildMeasurements($uom, $total);
        $retail = $this->buildRetailPackaging((string) $product->product_code, (bool) $product->sell_on_retail, $uom);

        return [
            'organization_id' => $orgId,
            'product' => [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'unit_price' => $product->unit_price !== null ? (float) $product->unit_price : null,
                'last_cost_price' => $product->last_cost_price !== null ? (float) $product->last_cost_price : null,
                'product_weight_kg' => $product->product_weight !== null ? (float) $product->product_weight : null,
                'reorder_point_base' => $product->reorder_point !== null ? (float) $product->reorder_point : null,
                'reorder_point_label' => $product->reorder_point !== null
                    ? $this->stockUom->formatMixedStockDisplay((float) $product->reorder_point, $uom)['text']
                    : null,
                'sell_on_retail' => (bool) $product->sell_on_retail,
                'sell_on_bar' => (bool) ($product->sell_on_bar ?? false),
                'sell_on_hotel' => (bool) ($product->sell_on_hotel ?? false),
            ],
            'measurements' => $measurements,
            'stock' => [
                'shop_base' => $shop,
                'shop_qty_label' => $shopLabel,
                'store_base' => $store,
                'store_qty_label' => $storeLabel,
                'total_base' => $total,
                'total_qty_label' => $totalLabel,
                'note' => 'qty_*_base values are smallest packaging units in the ledger. Always quote *_qty_label to the user.',
            ],
            'retail_packaging' => $retail,
            'screens' => [
                ['label' => 'Product', 'path' => '/products/'.rawurlencode((string) $product->product_code)],
                ['label' => 'Products list', 'path' => '/products'],
                ['label' => 'Units of measure', 'path' => '/uoms'],
                ['label' => 'Retail package settings', 'path' => '/retail-package-settings'],
                ['label' => 'Current stock', 'path' => '/inventory/stock'],
            ],
            'how_centrix_works' => [
                'Stock quantities are stored in base (smallest) units for the product UoM.',
                'Display mixes full packs, optional middle packs, and remaining small units (e.g. "2 Bag, 40 kg").',
                'Sell on retail + retail package settings control POS retail vs wholesale entry and markup tiers — separate from the UoM conversion itself.',
                'product_weight is an optional kg field on the product card; it is not the stock UoM unless the UoM small unit is also kg.',
            ],
        ];
    }

    protected function findProduct(int $orgId, string $code, string $query): ?Product
    {
        $base = Product::query()
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at');

        if ($code !== '') {
            $exact = (clone $base)->where('product_code', $code)->first();
            if ($exact) {
                return $exact;
            }
        }

        $term = $code !== '' ? $code : $query;
        if ($term === '') {
            return null;
        }

        return (clone $base)
            ->where(function ($q) use ($term) {
                $q->where('product_code', 'like', '%'.$term.'%')
                    ->orWhere('product_name', 'like', '%'.$term.'%');
            })
            ->orderBy('product_name')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildMeasurements(?Uom $uom, float $exampleBaseQty): array
    {
        if (! $uom) {
            return [
                'configured' => false,
                'message' => 'This product has no unit of measure linked (unit_id). Configure UoMs at /uoms and assign on the product.',
            ];
        }

        $factor = StockCostCalculation::normalizedConversionFactor($uom->conversion_factor ?? 1);
        $fullLabel = trim((string) ($uom->full_name ?? '')) ?: 'pack';
        $smallLabel = trim((string) ($uom->small_packaging_label ?? ''));
        if ($smallLabel === '') {
            $smallLabel = trim((string) ($uom->uom_type ?? '')) ?: 'pcs';
        }
        $middleLabel = trim((string) ($uom->middle_packaging_label ?? ''));
        $middleFactor = (float) ($uom->middle_factor ?? 0);
        $usesSmall = (bool) ($uom->uses_small_packaging ?? true);

        $hierarchy = [];
        if ($usesSmall === false) {
            $hierarchy[] = [
                'level' => 'full',
                'label' => $fullLabel,
                'base_units_per_pack' => $factor,
                'role' => 'Only packaging level — stock still counts in base units internally.',
            ];
        } else {
            if ($factor > 1) {
                $hierarchy[] = [
                    'level' => 'full',
                    'label' => $fullLabel,
                    'base_units_per_pack' => $factor,
                    'role' => 'Wholesale / full package (e.g. bag, carton).',
                ];
            }
            if ($middleLabel !== '' && $middleFactor > 1) {
                $hierarchy[] = [
                    'level' => 'middle',
                    'label' => $middleLabel,
                    'base_units_per_pack' => $middleFactor,
                    'role' => 'Optional middle packaging between full and small.',
                ];
            }
            $hierarchy[] = [
                'level' => 'small',
                'label' => $smallLabel,
                'base_units_per_pack' => 1,
                'role' => 'Base ledger unit (kg, pcs, litres, etc.).',
            ];
        }

        $exampleQty = $exampleBaseQty > 0 ? $exampleBaseQty : $factor;
        $exampleLabel = $this->stockUom->formatMixedStockDisplay((float) $exampleQty, $uom)['text'];

        return [
            'configured' => true,
            'uom_id' => (int) $uom->id,
            'full_name' => $fullLabel,
            'uom_type' => $uom->uom_type,
            'conversion_factor' => $factor,
            'conversion_meaning' => $factor > 1
                ? "1 {$fullLabel} = {$factor} {$smallLabel} (base units)"
                : "1 display unit = 1 {$smallLabel} (single-piece UoM)",
            'full_package_label' => $fullLabel,
            'middle_package_label' => $middleLabel !== '' ? $middleLabel : null,
            'middle_factor' => $middleFactor > 1 ? $middleFactor : null,
            'small_package_label' => $smallLabel,
            'uses_small_packaging' => $usesSmall,
            'hierarchy' => $hierarchy,
            'example_base_qty' => (float) $exampleQty,
            'example_qty_label' => $exampleLabel,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildRetailPackaging(string $productCode, bool $sellOnRetail, ?Uom $uom): array
    {
        $payload = [
            'sell_on_retail' => $sellOnRetail,
            'configured' => false,
            'explanation' => $sellOnRetail
                ? 'Product is flagged sell_on_retail, but no retail_package_settings row was found — configure at /retail-package-settings.'
                : 'Sell on retail is off. POS sells using the product UoM / wholesale unit price unless retail packaging is enabled.',
        ];

        if (! Schema::hasTable('retail_package_settings')) {
            return $payload;
        }

        $rps = RetailPackageSetting::query()->where('product_code', $productCode)->first();
        if (! $rps) {
            return $payload;
        }

        $small = trim((string) ($uom?->small_packaging_label ?? $uom?->uom_type ?? 'base unit'));
        $full = trim((string) ($uom?->full_name ?? 'full pack'));

        return [
            'sell_on_retail' => $sellOnRetail,
            'configured' => true,
            'min_uom_measure' => $rps->min_uom_measure,
            'max_uom_measure' => $rps->max_uom_measure,
            'max_qty_measure' => $rps->max_qty_measure !== null ? (float) $rps->max_qty_measure : null,
            'markup_price' => $rps->markup_price !== null ? (float) $rps->markup_price : null,
            'wholesale_qty_measure' => $rps->wholesale_qty_measure !== null ? (float) $rps->wholesale_qty_measure : null,
            'wholesale_markup_price' => $rps->wholesale_markup_price !== null ? (float) $rps->wholesale_markup_price : null,
            'pricing_tiers' => is_array($rps->pricing_tiers) ? $rps->pricing_tiers : null,
            'explanation' => 'Retail packaging controls how cashiers enter qty and which markup applies when selling retail. '
                ."Stock still uses UoM base units ({$small}); full pack is typically {$full}. "
                .'Edit at /retail-package-settings.',
        ];
    }
}
