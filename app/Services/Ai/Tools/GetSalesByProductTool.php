<?php

namespace App\Services\Ai\Tools;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\AiNearMissHelper;
use App\Services\Ai\AiQtyLabelEnricher;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Sales totals by product for AI chat — supports @Product mentions / product_codes.
 */
class GetSalesByProductTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected AiQtyLabelEnricher $qtyLabels,
    ) {}

    public function name(): string
    {
        return 'get_sales_by_product';
    }

    public function description(): string
    {
        return 'Get Centrix sales by product (qty + revenue) for a date range. '
            .'Use when the user asks for a sales-by-product report, how much specific SKUs sold, '
            .'or @mentions one or more products for sales. '
            .'Pass product_codes from @Product mentions (exact SKUs). Optional product_names for search. '
            .'Prefer relative_date=this_month/last_7_days/today or from_date/to_date. '
            .'Never invent product sales — always call this tool. Quote qty_label when returned.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_codes' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Exact product_code values from @Product mentions.',
                ],
                'product_names' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Product names to resolve when codes are unknown.',
                ],
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_month_to_date'],
                    'description' => 'Prefer for natural language periods.',
                ],
                'year_month' => [
                    'type' => 'string',
                    'description' => 'Calendar month YYYY-MM.',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Range start YYYY-MM-DD.',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Range end YYYY-MM-DD.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Max products when no codes/names filter (default 25, max 50).',
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
            || $this->permissions->hasPermission($user, 'reports.sales_by_product.view', $gate)
            || $this->permissions->hasPermission($user, 'reports.view', $gate)
            || $this->permissions->hasPermission($user, 'sales.orders.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view sales by product.',
                'screens' => $this->screens(),
            ];
        }

        $orgId = (int) $organization->id;
        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $codes = $this->resolveProductCodes($orgId, $arguments);
        $requestedNames = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            (array) ($arguments['product_names'] ?? []),
        )));
        $limit = max(1, min(50, (int) ($arguments['limit'] ?? ($codes === [] ? 25 : 50))));

        if ($requestedNames !== [] && $codes === []) {
            $suggestions = $this->suggestClosestProducts($orgId, implode(' ', $requestedNames));

            return array_merge(
                AiNearMissHelper::noExact(
                    implode(', ', $requestedNames),
                    $suggestions['closest'],
                    $suggestions['alternatives'],
                    $this->screens(),
                    'Widen the date range or confirm the product SKU in Products.',
                ),
                [
                    'from_date' => $from,
                    'to_date' => $to,
                    'screens' => $this->screens(),
                ],
            );
        }

        $rows = $this->querySales($orgId, $from, $to, $codes, $limit);
        $rows = $this->qtyLabels->enrichProductRows($orgId, $rows);

        $totalAmount = round(array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $rows)), 2);

        if ($rows === [] && $codes !== []) {
            $label = implode(', ', $codes);

            return [
                'from_date' => $from,
                'to_date' => $to,
                'currency' => 'KES',
                'filtered_product_codes' => $codes,
                'products' => [],
                'summary' => [
                    'product_count' => 0,
                    'total_amount' => 0,
                ],
                'near_miss' => true,
                'searched_for' => $label,
                'match_type' => 'no_sales_in_period',
                'message' => AiNearMissHelper::formatNoExact(
                    $label,
                    null,
                    [],
                    "No sales were recorded for these products between {$from} and {$to}. Try a wider date range.",
                ),
                'screens' => $this->screens(),
                'tip' => 'Explain no sales in the period, suggest widening dates, and link /reports/sales-by-product.',
            ];
        }

        return [
            'from_date' => $from,
            'to_date' => $to,
            'currency' => 'KES',
            'filtered_product_codes' => $codes,
            'products' => $rows,
            'summary' => [
                'product_count' => count($rows),
                'total_amount' => $totalAmount,
            ],
            'screens' => $this->screens(),
            'tip' => $rows === []
                ? 'No sales for these products in the period. Suggest widening dates or open /reports/sales-by-product.'
                : 'Present a markdown table: Product | Qty (qty_label) | Amount (KES). Use product names only — never include product_code / Code columns. Period: from_date–to_date.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<string>
     */
    protected function resolveProductCodes(int $organizationId, array $arguments): array
    {
        $codes = [];
        foreach ((array) ($arguments['product_codes'] ?? []) as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $codes[strtoupper($code)] = $code;
            }
        }

        $names = array_values(array_filter(array_map(
            fn ($n) => trim((string) $n),
            (array) ($arguments['product_names'] ?? []),
        )));

        if ($names !== [] && Schema::hasTable('products')) {
            foreach ($names as $name) {
                $needle = mb_strtolower($name);
                $matches = Product::query()
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($needle) {
                        $q->whereRaw('LOWER(product_name) LIKE ?', ['%'.$needle.'%'])
                            ->orWhereRaw('LOWER(product_code) LIKE ?', ['%'.$needle.'%']);
                    })
                    ->orderBy('product_name')
                    ->limit(5)
                    ->get(['product_code']);
                foreach ($matches as $row) {
                    $code = (string) $row->product_code;
                    $codes[strtoupper($code)] = $code;
                }
            }
        }

        return array_values($codes);
    }

    /**
     * @return array{closest: ?array<string, mixed>, alternatives: list<array<string, mixed>>}
     */
    protected function suggestClosestProducts(int $organizationId, string $searched): array
    {
        if (! Schema::hasTable('products')) {
            return ['closest' => null, 'alternatives' => []];
        }

        $tokens = AiNearMissHelper::tokens($searched);
        if ($tokens === []) {
            return ['closest' => null, 'alternatives' => []];
        }

        $rows = Product::query()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 2) {
                        continue;
                    }
                    $like = '%'.$token.'%';
                    $q->orWhere('product_code', 'like', $like)
                        ->orWhere('product_name', 'like', $like);
                }
            })
            ->orderBy('product_name')
            ->limit(10)
            ->get(['product_code', 'product_name']);

        $ranked = $rows->map(function (Product $p) use ($searched) {
            $label = trim((string) $p->product_name) !== ''
                ? trim((string) $p->product_name)
                : (string) $p->product_code;

            return [
                'label' => $label,
                'reason' => AiNearMissHelper::matchReason($searched, (string) $p->product_name),
                'score' => AiNearMissHelper::scoreNameMatch($searched, (string) $p->product_name),
            ];
        })->sortByDesc('score')->values()->all();

        return AiNearMissHelper::splitRankedMatches($ranked);
    }

    /**
     * @param  list<string>  $codes
     * @return list<array<string, mixed>>
     */
    protected function querySales(int $organizationId, string $from, string $to, array $codes, int $limit): array
    {
        if ($this->viewExists('v_sales_by_product')) {
            $query = DB::table('v_sales_by_product')
                ->where('organization_id', $organizationId)
                ->whereBetween('sale_date', [$from, $to]);
            if ($codes !== []) {
                $query->whereIn('product_code', $codes);
            }

            return $query
                ->selectRaw('product_code, MAX(product_name) as product_name, SUM(qty_sold) as qty, SUM(total_revenue) as amount')
                ->groupBy('product_code')
                ->orderByDesc('amount')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'product_code' => (string) $row->product_code,
                    'product_name' => (string) ($row->product_name ?? $row->product_code),
                    'qty' => round((float) $row->qty, 4),
                    'amount' => round((float) $row->amount, 2),
                ])
                ->all();
        }

        if (! Schema::hasTable('sale_items') || ! Schema::hasTable('sales')) {
            return [];
        }

        $query = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.organization_id', $organizationId)
            ->whereNull('s.deleted_at')
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, $to]);
        if ($codes !== []) {
            $query->whereIn('si.product_code', $codes);
        }

        return $query
            ->selectRaw('si.product_code, MAX(COALESCE(si.product_name, si.product_code)) as product_name, SUM(si.quantity) as qty, SUM(si.amount) as amount')
            ->groupBy('si.product_code')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_code' => (string) $row->product_code,
                'product_name' => (string) ($row->product_name ?? $row->product_code),
                'qty' => round((float) $row->qty, 4),
                'amount' => round((float) $row->amount, 2),
            ])
            ->all();
    }

    protected function viewExists(string $view): bool
    {
        try {
            DB::select('SELECT 1 FROM '.$view.' LIMIT 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(): array
    {
        return [
            ['label' => 'Sales by product', 'path' => '/reports/sales-by-product'],
            ['label' => 'Sales dashboard', 'path' => '/dashboard'],
        ];
    }
}
