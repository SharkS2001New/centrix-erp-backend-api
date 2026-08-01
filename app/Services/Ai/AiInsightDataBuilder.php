<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\Concerns\BuildsExtendedInsightSlices;
use App\Services\Inventory\LowStockReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bounded JSON slices for AI insights (no unbounded dumps).
 */
class AiInsightDataBuilder
{
    use BuildsExtendedInsightSlices;

    public const MAX_REPORT_ROWS = 80;

    public function __construct(
        protected LowStockReportService $lowStock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    public function reportSlice(
        Organization $organization,
        User $user,
        string $reportKey,
        array $filters = [],
        array $rows = [],
        ?array $summary = null,
    ): array {
        $capped = array_slice($rows, 0, self::MAX_REPORT_ROWS);

        return [
            'type' => 'report_analyze',
            'report_key' => $reportKey,
            'organization' => $organization->org_name ?? $organization->name,
            'filters' => $filters,
            'summary' => $summary,
            'row_count' => count($rows),
            'rows_sample' => $capped,
            'note' => count($rows) > self::MAX_REPORT_ROWS
                ? 'Only the first '.self::MAX_REPORT_ROWS.' rows were included.'
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function stockPulseSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $branchId = (int) ($user->branch_id ?? 0);

        $request = Request::create('/reports/low-stock', 'GET', [
            'per_page' => 40,
            'page' => 1,
            ...( $branchId > 0 ? ['branch_id' => $branchId] : []),
        ]);
        $request->setUserResolver(fn () => $user);

        $lowStock = $this->lowStock->paginate($request, $orgId);
        $lowRows = array_slice($lowStock['data'] ?? [], 0, 30);

        $from = now()->subDays($lookbackDays)->toDateString();
        $to = now()->toDateString();
        $movers = $this->topProductSales($orgId, $from, $to, 20);

        return [
            'type' => 'stock_pulse',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'from_date' => $from,
            'to_date' => $to,
            'low_stock_count' => (int) ($lowStock['meta']['total'] ?? count($lowRows)),
            'low_stock_items' => $lowRows,
            'fast_movers' => $movers,
            'actions_hint' => [
                ['label' => 'Open low stock report', 'href' => '/reports/low-stock'],
                ['label' => 'Open stock on hand', 'href' => '/reports/stock-on-hand'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function salesBriefSlice(Organization $organization, User $user, int $lookbackDays = 7): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();
        $to = now()->toDateString();
        $prevFrom = now()->subDays($lookbackDays * 2)->toDateString();
        $prevTo = now()->subDays($lookbackDays + 1)->toDateString();

        $currentTotal = $this->salesTotal($orgId, $from, $to);
        $previousTotal = $this->salesTotal($orgId, $prevFrom, $prevTo);
        $daily = $this->dailySales($orgId, $from, $to, 14);
        $topProducts = $this->topProductSales($orgId, $from, $to, 15);
        $topCustomers = $this->topCustomerSales($orgId, $from, $to, 10);
        $unpaid = $this->unpaidSnapshot($orgId);

        return [
            'type' => 'sales_brief',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'from_date' => $from,
            'to_date' => $to,
            'sales_total' => $currentTotal,
            'previous_period_total' => $previousTotal,
            'change_pct' => $previousTotal > 0
                ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
                : null,
            'daily_sales' => $daily,
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
            'unpaid' => $unpaid,
            'actions_hint' => [
                ['label' => 'Sales by product', 'href' => '/reports/sales-by-product'],
                ['label' => 'AR aging', 'href' => '/reports/ar-aging'],
                ['label' => 'Mobile orders', 'href' => '/sales/orders/queues/mobile'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function dashboardCards(Organization $organization, User $user): array
    {
        $orgId = (int) $organization->id;
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $weekAgo = now()->subDays(7)->toDateString();

        $request = Request::create('/reports/low-stock', 'GET', ['per_page' => 1, 'page' => 1]);
        $request->setUserResolver(fn () => $user);
        $low = $this->lowStock->paginate($request, $orgId);
        $lowCount = (int) ($low['meta']['total'] ?? 0);

        $yesterdaySales = $this->salesTotal($orgId, $yesterday, $yesterday);
        $weekSales = $this->salesTotal($orgId, $weekAgo, $today);
        $unpaid = $this->unpaidSnapshot($orgId);

        return [
            'cards' => [
                [
                    'id' => 'low_stock',
                    'label' => 'Below reorder',
                    'value' => $lowCount,
                    'hint' => $lowCount === 1 ? '1 SKU needs attention' : "{$lowCount} SKUs need attention",
                    'href' => '/reports/low-stock',
                    'insight_type' => 'stock_pulse',
                ],
                [
                    'id' => 'yesterday_sales',
                    'label' => 'Yesterday sales',
                    'value' => $yesterdaySales,
                    'hint' => 'KES '.number_format($yesterdaySales, 2),
                    'href' => '/reports/daily-sales',
                    'insight_type' => 'sales_brief',
                ],
                [
                    'id' => 'week_sales',
                    'label' => 'Last 7 days sales',
                    'value' => $weekSales,
                    'hint' => 'KES '.number_format($weekSales, 2),
                    'href' => '/reports/daily-sales',
                    'insight_type' => 'sales_brief',
                ],
                [
                    'id' => 'unpaid',
                    'label' => 'Unpaid / partial orders',
                    'value' => (int) ($unpaid['order_count'] ?? 0),
                    'hint' => 'KES '.number_format((float) ($unpaid['balance_due'] ?? 0), 2).' due',
                    'href' => '/sales/orders/queues/pending_payment',
                    'insight_type' => 'debtors_brief',
                ],
                [
                    'id' => 'exceptions',
                    'label' => 'Morning radar',
                    'value' => 'Scan',
                    'hint' => 'Low stock, unpaid, discounts, voids',
                    'href' => '/reports',
                    'insight_type' => 'exception_radar',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function topProductSales(int $organizationId, string $from, string $to, int $limit): array
    {
        if (! $this->viewExists('v_sales_by_product')) {
            return [];
        }

        return DB::table('v_sales_by_product')
            ->where('organization_id', $organizationId)
            ->whereBetween('sale_date', [$from, $to])
            ->selectRaw('product_code, product_name, SUM(qty_sold) as qty, SUM(total_revenue) as amount')
            ->groupBy('product_code', 'product_name')
            ->orderByDesc('amount')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'qty' => (float) $row->qty,
                'amount' => round((float) $row->amount, 2),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function topCustomerSales(int $organizationId, string $from, string $to, int $limit): array
    {
        // Prefer live sales aggregation when the customer view has no date dimension.
        if (Schema::hasTable('sales')) {
            return DB::table('sales')
                ->leftJoin('customers', function ($join) use ($organizationId) {
                    $join->on('customers.customer_num', '=', 'sales.customer_num')
                        ->where('customers.organization_id', '=', $organizationId);
                })
                ->where('sales.organization_id', $organizationId)
                ->whereNotIn('sales.status', ['cancelled', 'draft', 'held', 'expired'])
                ->whereRaw('DATE(COALESCE(sales.completed_at, sales.created_at)) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw('sales.customer_num, COALESCE(customers.customer_name, sales.customer_num) as customer_name, SUM(sales.order_total) as amount, COUNT(*) as orders')
                ->groupBy('sales.customer_num', 'customers.customer_name')
                ->orderByDesc('amount')
                ->limit($limit)
                ->get()
                ->map(fn ($row) => [
                    'customer_num' => $row->customer_num,
                    'customer_name' => $row->customer_name,
                    'amount' => round((float) $row->amount, 2),
                    'orders' => (int) $row->orders,
                ])
                ->all();
        }

        if (! $this->viewExists('v_sales_by_customer')) {
            return [];
        }

        return DB::table('v_sales_by_customer')
            ->where('organization_id', $organizationId)
            ->orderByDesc('total_purchased')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'customer_num' => $row->customer_num,
                'customer_name' => $row->customer_name,
                'amount' => round((float) ($row->total_purchased ?? 0), 2),
                'orders' => (int) ($row->total_orders ?? 0),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function dailySales(int $organizationId, string $from, string $to, int $limit): array
    {
        if (! $this->viewExists('v_daily_sales')) {
            return [];
        }

        return DB::table('v_daily_sales')
            ->where('organization_id', $organizationId)
            ->whereBetween('sale_day', [$from, $to])
            ->selectRaw('sale_day as sale_date, SUM(orders) as order_count, SUM(gross) as order_total')
            ->groupBy('sale_day')
            ->orderByDesc('sale_day')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'sale_date' => $row->sale_date,
                'order_count' => (int) ($row->order_count ?? 0),
                'order_total' => round((float) ($row->order_total ?? 0), 2),
            ])
            ->all();
    }

    protected function salesTotal(int $organizationId, string $from, string $to): float
    {
        if ($this->viewExists('v_daily_sales')) {
            return round((float) DB::table('v_daily_sales')
                ->where('organization_id', $organizationId)
                ->whereBetween('sale_day', [$from, $to])
                ->sum('gross'), 2);
        }

        return round((float) DB::table('sales')
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, $to])
            ->sum('order_total'), 2);
    }

    /** @return array{order_count: int, balance_due: float} */
    protected function unpaidSnapshot(int $organizationId): array
    {
        $rows = DB::table('sales')
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereIn('payment_status', ['unpaid', 'partial', 'partially_paid'])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(GREATEST(order_total - amount_paid, 0)), 0) as balance_due')
            ->first();

        return [
            'order_count' => (int) ($rows->order_count ?? 0),
            'balance_due' => round((float) ($rows->balance_due ?? 0), 2),
        ];
    }

    protected function viewExists(string $view): bool
    {
        static $cache = [];
        if (array_key_exists($view, $cache)) {
            return $cache[$view];
        }

        try {
            $schema = DB::getDatabaseName();
            $cache[$view] = DB::table('information_schema.views')
                ->where('table_schema', $schema)
                ->where('table_name', $view)
                ->exists();
        } catch (\Throwable) {
            $cache[$view] = false;
        }

        return $cache[$view];
    }
}
