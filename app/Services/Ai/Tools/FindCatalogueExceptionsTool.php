<?php

namespace App\Services\Ai\Tools;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Catalogue comparison / exception scans for AI chat
 * (cost vs selling, missing prices, thin margin, reorder gaps, etc.).
 */
class FindCatalogueExceptionsTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public const CHECKS = [
        'cost_above_selling',
        'zero_selling_price',
        'zero_cost_price',
        'thin_margin',
        'missing_supplier',
        'missing_reorder_point',
        'below_reorder',
        'missing_vat',
        'missing_uom',
        'all',
    ];

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
    ) {}

    public function name(): string
    {
        return 'find_catalogue_exceptions';
    }

    public function description(): string
    {
        return 'Scan the Centrix product catalogue for comparison / data-quality exceptions. '
            .'Use for questions like: cost higher than selling price, selling price zero/missing, '
            .'cost missing, thin/low margin, products without supplier, without reorder point, '
            .'stock at or below reorder, missing VAT, or missing unit of measure. '
            .'Pass check=cost_above_selling for "cost > selling". Pass check=all for a health overview. '
            .'Not the same as margin_discount_watchdog (that is recent sales lines sold below cost).';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'check' => [
                    'type' => 'string',
                    'enum' => self::CHECKS,
                    'description' => 'Which catalogue exception to scan. Default all.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max products to return per check (1–100). Default 25.',
                ],
                'margin_pct_below' => [
                    'type' => 'number',
                    'description' => 'For thin_margin: flag when gross margin % is below this (default 5).',
                ],
                'in_stock_only' => [
                    'type' => 'boolean',
                    'description' => 'When true, only products with shop+store stock > 0.',
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

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'catalogue.products.view', $gate)
            || $this->permissions->hasPermission($user, 'inventory.stock.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to scan the product catalogue.',
                'screens' => [
                    ['label' => 'Products', 'path' => '/products'],
                ],
            ];
        }

        $orgId = (int) $organization->id;
        $check = str_replace('-', '_', strtolower(trim((string) ($arguments['check'] ?? 'all'))));
        if ($check === '' || ! in_array($check, self::CHECKS, true)) {
            $check = 'all';
        }

        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 25)));
        $marginBelow = (float) ($arguments['margin_pct_below'] ?? 5);
        if ($marginBelow < 0) {
            $marginBelow = 0;
        }
        if ($marginBelow > 100) {
            $marginBelow = 100;
        }
        $inStockOnly = filter_var($arguments['in_stock_only'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $checksToRun = $check === 'all'
            ? array_values(array_filter(self::CHECKS, fn (string $c) => $c !== 'all'))
            : [$check];

        $buckets = [];
        $totals = [];
        foreach ($checksToRun as $key) {
            [$total, $rows] = $this->runCheck($orgId, $key, $limit, $marginBelow, $inStockOnly);
            $totals[$key] = $total;
            $buckets[$key] = $rows;
        }

        $screens = [
            ['label' => 'Products', 'path' => '/products'],
            ['label' => 'Current stock', 'path' => '/inventory/stock'],
            ['label' => 'Price history', 'path' => '/price-history'],
        ];

        return [
            'check' => $check,
            'in_stock_only' => $inStockOnly,
            'margin_pct_below' => $marginBelow,
            'totals' => $totals,
            'products' => $check === 'all' ? $buckets : ($buckets[$check] ?? []),
            'checks_run' => $checksToRun,
            'check_labels' => $this->checkLabels(),
            'note' => 'Catalogue master-data scan (unit_price vs last_cost_price on products). '
                .'For lines recently sold below cost, use run_insight with margin_discount_watchdog.',
            'screens' => $screens,
            'tip' => 'Quote product_code, product_name, unit_price, last_cost_price, and margin_pct from the tool. '
                .'Link /products for fixes. Do not invent SKUs.',
        ];
    }

    /**
     * @return array{0: int, 1: list<array<string, mixed>>}
     */
    protected function runCheck(
        int $orgId,
        string $check,
        int $limit,
        float $marginBelow,
        bool $inStockOnly,
    ): array {
        $base = $this->baseQuery($orgId, $inStockOnly);

        match ($check) {
            'cost_above_selling' => $base
                ->whereNotNull('last_cost_price')
                ->where('last_cost_price', '>', 0)
                ->whereNotNull('unit_price')
                ->whereColumn('last_cost_price', '>', 'unit_price'),
            'zero_selling_price' => $base->where(function (Builder $q) {
                $q->whereNull('unit_price')->orWhere('unit_price', '<=', 0);
            }),
            'zero_cost_price' => $base->where(function (Builder $q) {
                $q->whereNull('last_cost_price')->orWhere('last_cost_price', '<=', 0);
            }),
            'thin_margin' => $base
                ->whereNotNull('unit_price')
                ->where('unit_price', '>', 0)
                ->whereNotNull('last_cost_price')
                ->where('last_cost_price', '>=', 0)
                ->whereRaw(
                    '((unit_price - last_cost_price) / unit_price) * 100 < ?',
                    [$marginBelow],
                )
                // Cost above selling is its own check; still include here as margin < threshold.
                ,
            'missing_supplier' => $base->where(function (Builder $q) {
                $q->whereNull('supplier_id')->orWhere('supplier_id', 0);
            }),
            'missing_reorder_point' => $base->where(function (Builder $q) {
                $q->whereNull('reorder_point')->orWhere('reorder_point', '<=', 0);
            }),
            'below_reorder' => $base
                ->whereNotNull('reorder_point')
                ->where('reorder_point', '>', 0)
                ->whereRaw(
                    '(COALESCE(stock_in_shop, 0) + COALESCE(stock_in_store, 0)) <= reorder_point',
                ),
            'missing_vat' => $base->where(function (Builder $q) {
                $q->whereNull('vat_id')->orWhere('vat_id', 0);
            }),
            'missing_uom' => $base->where(function (Builder $q) {
                $q->whereNull('unit_id')->orWhere('unit_id', 0);
            }),
            default => null,
        };

        $total = (clone $base)->count();
        $rows = $base
            ->orderBy('product_name')
            ->limit($limit)
            ->get([
                'product_code',
                'product_name',
                'unit_price',
                'last_cost_price',
                'stock_in_shop',
                'stock_in_store',
                'reorder_point',
                'supplier_id',
                'vat_id',
                'unit_id',
            ])
            ->map(fn (Product $p) => $this->mapProduct($p))
            ->all();

        return [$total, $rows];
    }

    protected function baseQuery(int $orgId, bool $inStockOnly): Builder
    {
        $q = Product::query()->where('organization_id', $orgId);
        if (Schema::hasColumn('products', 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($inStockOnly) {
            $q->whereRaw('(COALESCE(stock_in_shop, 0) + COALESCE(stock_in_store, 0)) > 0.0001');
        }

        return $q;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapProduct(Product $p): array
    {
        $unit = (float) ($p->unit_price ?? 0);
        $cost = (float) ($p->last_cost_price ?? 0);
        $shop = (float) ($p->stock_in_shop ?? 0);
        $store = (float) ($p->stock_in_store ?? 0);
        $marginPct = null;
        if ($unit > 0.0001) {
            $marginPct = round((($unit - $cost) / $unit) * 100, 2);
        }

        return [
            'product_code' => (string) $p->product_code,
            'product_name' => (string) $p->product_name,
            'unit_price' => round($unit, 4),
            'last_cost_price' => round($cost, 4),
            'margin_pct' => $marginPct,
            'stock_on_hand' => round($shop + $store, 4),
            'reorder_point' => $p->reorder_point !== null ? (float) $p->reorder_point : null,
            'supplier_id' => $p->supplier_id !== null ? (int) $p->supplier_id : null,
            'has_vat' => ! empty($p->vat_id),
            'has_uom' => ! empty($p->unit_id),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function checkLabels(): array
    {
        return [
            'cost_above_selling' => 'Cost price higher than selling price',
            'zero_selling_price' => 'Zero or missing selling price',
            'zero_cost_price' => 'Zero or missing cost price',
            'thin_margin' => 'Thin / low gross margin on catalogue prices',
            'missing_supplier' => 'No supplier linked',
            'missing_reorder_point' => 'No reorder point set',
            'below_reorder' => 'Stock at or below reorder point',
            'missing_vat' => 'No VAT rate linked',
            'missing_uom' => 'No unit of measure linked',
        ];
    }
}
