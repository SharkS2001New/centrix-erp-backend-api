<?php

namespace App\Services\Ai\Concerns;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Inventory\StockCostCalculation;
use App\Services\Inventory\StockValuationService;
use App\Services\Sales\CentrixSalesScope;
use App\Support\EffectiveSaleDate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JSON slices for BI chat tools (profit, expenses, portfolio, valuation, cash, scenarios).
 */
trait BuildsBiToolSlices
{
    /** @return array<string, mixed> */
    public function insightDataSlice(
        Organization $organization,
        User $user,
        string $type,
        int $lookbackDays = 7,
        array $options = [],
    ): array {
        $type = str_replace('-', '_', trim($type));

        return match ($type) {
            'stock_pulse' => $this->stockPulseSlice($organization, $user, $lookbackDays),
            'sales_brief' => $this->salesBriefSlice($organization, $user, $lookbackDays),
            'debtors_brief' => $this->debtorsBriefSlice($organization, $user, $lookbackDays),
            'cash_till_health' => $this->cashTillHealthSlice($organization, $user, $lookbackDays),
            'route_mobile_debrief' => $this->routeMobileDebriefSlice($organization, $user, $lookbackDays),
            'exception_radar' => $this->exceptionRadarSlice($organization, $user, $lookbackDays),
            'product_demand' => $this->productDemandSlice(
                $organization,
                $user,
                $lookbackDays,
                $options['product_code'] ?? null,
                $options['product_query'] ?? null,
            ),
            'customer_360' => $this->customer360Slice(
                $organization,
                $user,
                (string) ($options['customer_num'] ?? ''),
                $lookbackDays,
            ),
            'margin_discount_watchdog' => $this->marginDiscountWatchdogSlice($organization, $user, $lookbackDays),
            'procurement_companion' => $this->procurementCompanionSlice($organization, $user, $lookbackDays),
            'collections_playbook' => $this->collectionsPlaybookSlice($organization, $user, $lookbackDays),
            'anomaly_detection' => $this->anomalyDetectionSlice($organization, $user, $lookbackDays),
            'forecast_light' => $this->forecastLightSlice($organization, $user, $lookbackDays),
            'branch_till_benchmarks' => $this->branchTillBenchmarksSlice($organization, $user, $lookbackDays),
            default => ['error' => true, 'message' => 'Unknown insight type: '.$type],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function profitLossSlice(
        Organization $organization,
        User $user,
        string $from,
        string $to,
        ?int $branchId = null,
        bool $comparePrevious = true,
        bool $includeTopProducts = true,
        bool $includeBranches = false,
    ): array {
        $orgId = (int) $organization->id;
        $current = $this->profitLossTotals($orgId, $branchId, $from, $to);

        $previous = null;
        $change = null;
        if ($comparePrevious) {
            $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
            $prevTo = Carbon::parse($from)->subDay()->toDateString();
            $prevFrom = Carbon::parse($prevTo)->subDays($days - 1)->toDateString();
            $previous = $this->profitLossTotals($orgId, $branchId, $prevFrom, $prevTo);
            $change = $this->periodChange($current, $previous);
        }

        $payload = [
            'type' => 'profit_loss',
            'organization' => $organization->org_name ?? $organization->name,
            'period' => ['from_date' => $from, 'to_date' => $to],
            'branch_id' => $branchId,
            'current' => $current,
            'previous_period' => $previous,
            'change' => $change,
            'actions_hint' => [
                ['label' => 'Profit & loss', 'href' => '/reports/profit-loss'],
                ['label' => 'P&L by product', 'href' => '/reports/profit-loss-by-product'],
            ],
        ];

        if ($includeTopProducts) {
            $payload['top_products_by_gross_profit'] = $this->topProductsByGrossProfit(
                $orgId,
                $branchId,
                $from,
                $to,
                15,
            );
        }

        if ($includeBranches && $branchId === null) {
            $payload['by_branch'] = $this->profitLossByBranch($orgId, $from, $to, 10);
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function expenseSummarySlice(
        Organization $organization,
        User $user,
        string $from,
        string $to,
        ?int $branchId = null,
        ?int $recordedByUserId = null,
    ): array {
        $orgId = (int) $organization->id;
        $currentByCategory = $this->expensesByCategory($orgId, $branchId, $from, $to, $recordedByUserId);
        $currentTotal = round(array_sum(array_column($currentByCategory, 'amount')), 2);

        $days = max(1, Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1);
        $prevTo = Carbon::parse($from)->subDay()->toDateString();
        $prevFrom = Carbon::parse($prevTo)->subDays($days - 1)->toDateString();
        $previousByCategory = $this->expensesByCategory($orgId, $branchId, $prevFrom, $prevTo, $recordedByUserId);
        $previousTotal = round(array_sum(array_column($previousByCategory, 'amount')), 2);

        $increases = [];
        $prevMap = collect($previousByCategory)->keyBy('category');
        foreach ($currentByCategory as $row) {
            $prevAmt = (float) ($prevMap[$row['category']]['amount'] ?? 0);
            $delta = round($row['amount'] - $prevAmt, 2);
            if ($delta > 0) {
                $increases[] = [
                    'category' => $row['category'],
                    'current' => $row['amount'],
                    'previous' => $prevAmt,
                    'increase' => $delta,
                    'increase_pct' => $prevAmt > 0 ? round(($delta / $prevAmt) * 100, 1) : null,
                ];
            }
        }
        usort($increases, fn ($a, $b) => $b['increase'] <=> $a['increase']);

        $lines = [];
        if ($recordedByUserId !== null && Schema::hasTable('expenses') && Schema::hasColumn('expenses', 'recorded_by')) {
            $lines = $this->expenseLinesForUser($orgId, $branchId, $from, $to, $recordedByUserId);
        }

        $mobileRoute = $this->mobileRouteExpensesForUser($orgId, $from, $to, $recordedByUserId);
        $mobileTotal = round(array_sum(array_column($mobileRoute, 'amount')), 2);

        return [
            'type' => 'expense_summary',
            'organization' => $organization->org_name ?? $organization->name,
            'period' => ['from_date' => $from, 'to_date' => $to],
            'branch_id' => $branchId,
            'recorded_by_user_id' => $recordedByUserId,
            'attribution' => $recordedByUserId !== null
                ? 'filtered_by_user'
                : 'organization_wide',
            'how_centrix_works' => [
                'Accounting expenses store who entered them in expenses.recorded_by.',
                'Mobile route expenses (when enabled) store the rep in mobile_route_expenses.user_id.',
                'Never claim Centrix cannot show a person\'s expenses — filter by user_name / username on this tool.',
            ],
            'total_expenses' => $currentTotal,
            'previous_period_total' => $previousTotal,
            'change_pct' => $previousTotal > 0
                ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
                : null,
            'by_category' => $currentByCategory,
            'previous_by_category' => $previousByCategory,
            'largest_increases' => array_slice($increases, 0, 10),
            'expense_lines' => $lines,
            'mobile_route_expenses' => [
                'total' => $mobileTotal,
                'count' => count($mobileRoute),
                'lines' => $mobileRoute,
            ],
            'combined_user_total' => $recordedByUserId !== null
                ? round($currentTotal + $mobileTotal, 2)
                : null,
            'actions_hint' => [
                ['label' => 'Expenses', 'href' => '/expenses'],
                ['label' => 'Mobile orders', 'href' => '/sales/orders/queues/mobile'],
                ['label' => 'Profit & loss', 'href' => '/reports/profit-loss'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function customerPortfolioSlice(
        Organization $organization,
        User $user,
        int $lookbackDays = 90,
        int $inactiveDays = 45,
        int $limit = 20,
    ): array {
        $lookbackDays = max(14, min(180, $lookbackDays));
        $inactiveDays = max(14, min(365, $inactiveDays));
        $limit = max(5, min(50, $limit));
        $orgId = (int) $organization->id;
        $to = now()->toDateString();
        $from = now()->subDays($lookbackDays)->toDateString();
        $prevTo = Carbon::parse($from)->subDay()->toDateString();
        $prevFrom = Carbon::parse($prevTo)->subDays($lookbackDays)->toDateString();
        $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();

        $topByRevenue = $this->topCustomerSales($orgId, $from, $to, $limit);

        $declining = DB::table('sales as cur')
            ->leftJoin('customers as c', function ($join) use ($orgId) {
                $join->on('c.customer_num', '=', 'cur.customer_num')
                    ->where('c.organization_id', '=', $orgId);
            })
            ->where('cur.organization_id', $orgId)
            ->whereNotNull('cur.customer_num')
            ->whereIn('cur.status', $metricStatuses)
            ->where('cur.archived', 0)
            ->whereRaw('DATE(COALESCE(cur.completed_at, cur.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('
                cur.customer_num,
                COALESCE(c.customer_name, cur.customer_name_override, cur.customer_num) as customer_name,
                ROUND(SUM(cur.order_total), 2) as current_revenue
            ')
            ->groupBy('cur.customer_num', 'c.customer_name', 'cur.customer_name_override')
            ->get()
            ->map(function ($row) use ($orgId, $prevFrom, $prevTo, $metricStatuses) {
                $prev = (float) DB::table('sales')
                    ->where('organization_id', $orgId)
                    ->where('customer_num', $row->customer_num)
                    ->whereIn('status', $metricStatuses)
                    ->where('archived', 0)
                    ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$prevFrom, $prevTo])
                    ->sum('order_total');
                $current = (float) $row->current_revenue;
                if ($prev <= 0 || $current >= $prev * 0.5) {
                    return null;
                }

                return [
                    'customer_num' => $row->customer_num,
                    'customer_name' => $row->customer_name,
                    'current_revenue' => $current,
                    'previous_revenue' => round($prev, 2),
                    'decline_pct' => round((1 - ($current / $prev)) * 100, 1),
                ];
            })
            ->filter()
            ->sortByDesc('decline_pct')
            ->take($limit)
            ->values()
            ->all();

        $inactiveCutoff = now()->subDays($inactiveDays)->toDateString();
        $inactive = DB::table('customers as c')
            ->where('c.organization_id', $orgId)
            ->whereExists(function ($q) use ($orgId, $metricStatuses) {
                $q->selectRaw('1')
                    ->from('sales as s')
                    ->whereColumn('s.customer_num', 'c.customer_num')
                    ->where('s.organization_id', $orgId)
                    ->whereIn('s.status', $metricStatuses)
                    ->where('s.archived', 0);
            })
            ->whereNotExists(function ($q) use ($orgId, $metricStatuses, $inactiveCutoff) {
                $q->selectRaw('1')
                    ->from('sales as s')
                    ->whereColumn('s.customer_num', 'c.customer_num')
                    ->where('s.organization_id', $orgId)
                    ->whereIn('s.status', $metricStatuses)
                    ->where('s.archived', 0)
                    ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) >= ?', [$inactiveCutoff]);
            })
            ->orderBy('c.customer_name')
            ->limit($limit)
            ->get(['c.customer_num', 'c.customer_name', 'c.phone_number', 'c.credit_limit', 'c.current_balance'])
            ->map(fn ($r) => [
                'customer_num' => $r->customer_num,
                'customer_name' => $r->customer_name,
                'phone' => $r->phone_number,
                'credit_limit' => $r->credit_limit !== null ? (float) $r->credit_limit : null,
                'current_balance' => $r->current_balance !== null ? (float) $r->current_balance : null,
                'inactive_days_threshold' => $inactiveDays,
            ])
            ->all();

        $highCredit = DB::table('customers')
            ->where('organization_id', $orgId)
            ->where('credit_limit', '>', 0)
            ->whereRaw('current_balance / credit_limit >= 0.8')
            ->orderByDesc(DB::raw('current_balance / credit_limit'))
            ->limit($limit)
            ->get(['customer_num', 'customer_name', 'credit_limit', 'current_balance'])
            ->map(fn ($r) => [
                'customer_num' => $r->customer_num,
                'customer_name' => $r->customer_name,
                'credit_limit' => (float) $r->credit_limit,
                'current_balance' => (float) $r->current_balance,
                'credit_utilization_pct' => round(((float) $r->current_balance / (float) $r->credit_limit) * 100, 1),
            ])
            ->all();

        return [
            'type' => 'customer_portfolio',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'inactive_days_threshold' => $inactiveDays,
            'top_by_revenue' => $topByRevenue,
            'declining_purchases' => $declining,
            'inactive_customers' => $inactive,
            'high_credit_utilization' => $highCredit,
            'actions_hint' => [
                ['label' => 'Customers', 'href' => '/customers'],
                ['label' => 'AR aging', 'href' => '/reports/ar-aging'],
                ['label' => 'Unpaid debtors', 'href' => '/sales/shop-debtors/unpaid'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function inventoryValuationSlice(
        Organization $organization,
        User $user,
        ?int $branchId = null,
        int $topProductsLimit = 15,
    ): array {
        $orgId = (int) $organization->id;
        $branchId = $branchId ?? ((int) ($user->branch_id ?? 0) ?: null);
        $summary = app(StockValuationService::class)->summarize($orgId, $branchId);

        $topProducts = [];
        if (Schema::hasTable('v_stock_valuation')) {
            $q = DB::table('v_stock_valuation')
                ->where('organization_id', $orgId);
            if ($branchId !== null) {
                $q->where('branch_id', $branchId);
            }
            $topProducts = $q->orderByDesc('cost_value')
                ->limit(max(5, min(30, $topProductsLimit)))
                ->get(['product_code', 'product_name', 'total_qty', 'cost_value', 'retail_value'])
                ->map(fn ($r) => [
                    'product_code' => $r->product_code,
                    'product_name' => $r->product_name,
                    'quantity' => (float) ($r->total_qty ?? 0),
                    'cost_value' => round((float) ($r->cost_value ?? 0), 2),
                    'retail_value' => round((float) ($r->retail_value ?? 0), 2),
                ])
                ->all();
            $topProducts = $this->withQtyLabels($orgId, $topProducts, 'quantity');
        }

        $branchName = null;
        $branchCount = 1;
        if (Schema::hasTable('branches')) {
            $branchCount = (int) DB::table('branches')
                ->where('organization_id', $orgId)
                ->when(Schema::hasColumn('branches', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->count();
            if ($branchId !== null) {
                $branchName = DB::table('branches')
                    ->where('organization_id', $orgId)
                    ->where('id', $branchId)
                    ->value('branch_name');
            }
        }

        return [
            'type' => 'inventory_valuation',
            'organization' => $organization->org_name ?? $organization->name,
            'branch_name' => $branchName ? (string) $branchName : null,
            'multi_branch' => $branchCount > 1,
            'summary' => $summary,
            'top_products_by_cost_value' => $topProducts,
            'answer_tip' => 'Mention branch_name only when multi_branch is true. Never show branch_id. '
                .'For "items in stock" questions prefer get_stock_summary.in_stock_items, not valuation alone.',
            'actions_hint' => [
                ['label' => 'Stock valuation', 'href' => '/reports/stock-valuation'],
                ['label' => 'Stock on hand', 'href' => '/reports/stock-on-hand'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function cashPositionSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $orgId = (int) $organization->id;
        $branchId = (int) ($user->branch_id ?? 0) ?: null;
        $till = $this->cashTillHealthSlice($organization, $user, $lookbackDays);
        $receivables = $this->unpaidSnapshot($orgId);

        $glCash = $this->glCashBankBalances($orgId);
        $payables = $this->supplierPayablesTotal($orgId);

        $openTillCash = 0.0;
        if (Schema::hasTable('till_float_sessions')) {
            $openTillCash = (float) DB::table('till_float_sessions')
                ->where('organization_id', $orgId)
                ->whereIn('status', ['open', 'active'])
                ->sum(DB::raw('COALESCE(working_amount, 0)'));
        }

        $recentPaymentMix = $till['payment_mix'] ?? [];

        return [
            'type' => 'cash_position',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'till_open_float' => round($openTillCash, 2),
            'recent_payment_mix' => $recentPaymentMix,
            'gl_cash_and_bank' => $glCash,
            'accounts_receivable' => [
                'balance_due' => (float) ($receivables['balance_due'] ?? 0),
                'order_count' => (int) ($receivables['order_count'] ?? 0),
            ],
            'accounts_payable_estimate' => $payables,
            'note' => 'Till float and recent sales payment mix are operational; GL cash/bank are accounting balances. Do not double-count.',
            'actions_hint' => [
                ['label' => 'Cash flow report', 'href' => '/reports/cash-flow'],
                ['label' => 'Till sessions', 'href' => '/reports/till-sessions'],
                ['label' => 'Bank reconciliation', 'href' => '/accounting/bank-reconciliation'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $inputs
     * @return array<string, mixed>
     */
    public function scenarioCalculationSlice(
        Organization $organization,
        string $scenarioType,
        float $percent,
        array $inputs = [],
    ): array {
        $orgId = (int) $organization->id;
        $from = (string) ($inputs['from_date'] ?? now()->startOfMonth()->toDateString());
        $to = (string) ($inputs['to_date'] ?? now()->toDateString());
        $branchId = isset($inputs['branch_id']) ? (int) $inputs['branch_id'] : null;
        $pct = round($percent, 2);
        $factor = 1 + ($pct / 100);

        $baseline = $this->profitLossTotals($orgId, $branchId, $from, $to);
        $result = [
            'type' => 'scenario_calculation',
            'scenario' => $scenarioType,
            'percent_change' => $pct,
            'baseline_period' => ['from_date' => $from, 'to_date' => $to],
            'baseline' => $baseline,
            'assumptions' => ['volume_unchanged' => true],
            'disclaimer' => 'Illustrative estimate only — actual demand and costs may change after price/cost moves.',
        ];

        return match ($scenarioType) {
            'price_increase', 'sales_increase' => array_merge($result, [
                'projected' => [
                    'gross_revenue' => round($baseline['gross_revenue'] * $factor, 2),
                    'gross_profit' => round($baseline['gross_profit'] * $factor, 2),
                    'net_profit' => round($baseline['net_profit'] * $factor, 2),
                ],
                'delta' => [
                    'gross_revenue' => round($baseline['gross_revenue'] * ($factor - 1), 2),
                    'gross_profit' => round($baseline['gross_profit'] * ($factor - 1), 2),
                ],
            ]),
            'supplier_cost_increase' => array_merge($result, [
                'projected' => [
                    'cogs' => round($baseline['cogs'] * $factor, 2),
                    'gross_profit' => round($baseline['gross_revenue'] - ($baseline['cogs'] * $factor), 2),
                    'net_profit' => round($baseline['gross_revenue'] - ($baseline['cogs'] * $factor) - $baseline['total_expenses'], 2),
                ],
                'delta' => [
                    'cogs' => round($baseline['cogs'] * ($factor - 1), 2),
                    'gross_profit' => round(-$baseline['cogs'] * ($factor - 1), 2),
                ],
            ]),
            'discount_reduction' => array_merge($result, [
                'note' => 'Discount reduction impact requires discount totals from margin_discount_watchdog insight; baseline uses gross revenue as proxy.',
                'projected' => [
                    'gross_revenue' => round($baseline['gross_revenue'] * $factor, 2),
                    'gross_profit' => round($baseline['gross_profit'] * $factor, 2),
                ],
            ]),
            default => array_merge($result, [
                'error' => true,
                'message' => 'Unknown scenario type. Use price_increase, sales_increase, supplier_cost_increase, or discount_reduction.',
            ]),
        };
    }

    /** @return array<string, float|int|null> */
    protected function profitLossTotals(int $orgId, ?int $branchId, string $from, string $to): array
    {
        $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();

        $salesQuery = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sales')
                ->whereIn('status', $metricStatuses)
                ->where('archived', 0),
        );
        $this->applyBiSalesScope($salesQuery, $orgId, $branchId);
        EffectiveSaleDate::applyFromToDateFilter($salesQuery, $from, $to);

        $sales = $salesQuery
            ->selectRaw('COALESCE(SUM(order_total), 0) as gross_revenue, COALESCE(SUM(total_vat), 0) as vat_collected, COUNT(*) as order_count')
            ->first();

        $grossRevenue = (float) ($sales->gross_revenue ?? 0);
        $vatCollected = (float) ($sales->vat_collected ?? 0);

        $cogsQuery = DB::table('sale_items as si')
            ->join('sales as cs', 'cs.id', '=', 'si.sale_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->on('p.organization_id', '=', 'cs.organization_id');
            })
            ->leftJoin('uoms as uom', 'uom.id', '=', 'p.unit_id')
            ->whereIn('cs.status', $metricStatuses)
            ->where('cs.archived', 0);
        CentrixSalesScope::excludeLegacyMaterialized($cogsQuery, 'cs');
        $this->applyBiSalesScope($cogsQuery, $orgId, $branchId, 'cs');
        EffectiveSaleDate::applyFromToDateFilter($cogsQuery, $from, $to, 'cs');

        $cogsExpr = StockCostCalculation::costValueSqlExpression(
            'si.quantity',
            'COALESCE(p.last_cost_price, 0)',
            'uom',
        );
        $cogs = (float) $cogsQuery->selectRaw("COALESCE(SUM({$cogsExpr}), 0) as total_cost")->value('total_cost');

        $expenseQuery = DB::table('expenses')->whereNull('deleted_at');
        if ($orgId && Schema::hasColumn('expenses', 'organization_id')) {
            $expenseQuery->where('organization_id', $orgId);
        }
        if ($branchId !== null && Schema::hasColumn('expenses', 'branch_id')) {
            $expenseQuery->where('branch_id', $branchId);
        }
        $expenseQuery->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to);
        $totalExpenses = (float) $expenseQuery->sum('expense_amount');

        $grossProfit = $grossRevenue - $cogs;
        $netProfit = $grossProfit - $totalExpenses;

        return [
            'gross_revenue' => round($grossRevenue, 2),
            'vat_collected' => round($vatCollected, 2),
            'net_revenue' => round($grossRevenue - $vatCollected, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_pct' => $grossRevenue > 0 ? round(($grossProfit / $grossRevenue) * 100, 1) : null,
            'total_expenses' => round($totalExpenses, 2),
            'net_profit' => round($netProfit, 2),
            'net_margin_pct' => $grossRevenue > 0 ? round(($netProfit / $grossRevenue) * 100, 1) : null,
            'order_count' => (int) ($sales->order_count ?? 0),
        ];
    }

    /** @param  \Illuminate\Database\Query\Builder  $query */
    protected function applyBiSalesScope($query, int $orgId, ?int $branchId, string $alias = ''): void
    {
        $prefix = $alias !== '' ? $alias.'.' : '';
        if ($orgId && Schema::hasColumn('sales', 'organization_id')) {
            $query->where("{$prefix}organization_id", $orgId);
        }
        if ($branchId !== null && Schema::hasColumn('sales', 'branch_id')) {
            $query->where("{$prefix}branch_id", $branchId);
        }
    }

    /** @return list<array<string, mixed>> */
    protected function topProductsByGrossProfit(int $orgId, ?int $branchId, string $from, string $to, int $limit): array
    {
        $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();
        $cogsExpr = StockCostCalculation::costValueSqlExpression(
            'si.quantity',
            'COALESCE(p.last_cost_price, 0)',
            'uom',
        );

        $query = DB::table('sale_items as si')
            ->join('sales as cs', 'cs.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->on('p.organization_id', '=', 'cs.organization_id');
            })
            ->leftJoin('uoms as uom', 'uom.id', '=', 'p.unit_id')
            ->whereIn('cs.status', $metricStatuses)
            ->where('cs.archived', 0);
        CentrixSalesScope::excludeLegacyMaterialized($query, 'cs');
        $this->applyBiSalesScope($query, $orgId, $branchId, 'cs');
        EffectiveSaleDate::applyFromToDateFilter($query, $from, $to, 'cs');

        $rows = $query
            ->selectRaw("
                si.product_code,
                COALESCE(p.product_name, si.product_code) as product_name,
                SUM(si.amount) as gross_revenue,
                SUM({$cogsExpr}) as cogs
            ")
            ->groupBy('si.product_code', 'p.product_name')
            ->orderByDesc(DB::raw('SUM(si.amount) - SUM('.$cogsExpr.')'))
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $rev = (float) $r->gross_revenue;
                $cogs = (float) $r->cogs;
                $gp = $rev - $cogs;

                return [
                    'product_code' => $r->product_code,
                    'product_name' => $r->product_name,
                    'gross_revenue' => round($rev, 2),
                    'cogs' => round($cogs, 2),
                    'gross_profit' => round($gp, 2),
                    'gross_margin_pct' => $rev > 0 ? round(($gp / $rev) * 100, 1) : null,
                ];
            })
            ->all();

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    protected function profitLossByBranch(int $orgId, string $from, string $to, int $limit): array
    {
        if (! Schema::hasColumn('sales', 'branch_id')) {
            return [];
        }

        $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();

        $revenues = DB::table('sales as cs')
            ->leftJoin('branches as b', 'b.id', '=', 'cs.branch_id')
            ->where('cs.organization_id', $orgId)
            ->whereIn('cs.status', $metricStatuses)
            ->where('cs.archived', 0)
            ->whereRaw('DATE(COALESCE(cs.completed_at, cs.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('
                cs.branch_id,
                COALESCE(b.branch_name, CONCAT("Branch #", cs.branch_id)) as branch_name,
                ROUND(SUM(cs.order_total), 2) as gross_revenue
            ')
            ->groupBy('cs.branch_id', 'b.branch_name')
            ->get()
            ->keyBy('branch_id');

        $cogsExpr = StockCostCalculation::costValueSqlExpression(
            'si.quantity',
            'COALESCE(p.last_cost_price, 0)',
            'uom',
        );

        $cogsRows = DB::table('sale_items as si')
            ->join('sales as cs', 'cs.id', '=', 'si.sale_id')
            ->join('products as p', function ($join) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->on('p.organization_id', '=', 'cs.organization_id');
            })
            ->leftJoin('uoms as uom', 'uom.id', '=', 'p.unit_id')
            ->where('cs.organization_id', $orgId)
            ->whereIn('cs.status', $metricStatuses)
            ->where('cs.archived', 0)
            ->whereRaw('DATE(COALESCE(cs.completed_at, cs.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw("cs.branch_id, ROUND(SUM({$cogsExpr}), 2) as cogs")
            ->groupBy('cs.branch_id')
            ->get()
            ->keyBy('branch_id');

        return $revenues->map(function ($r) use ($cogsRows) {
            $rev = (float) $r->gross_revenue;
            $cogs = (float) ($cogsRows[$r->branch_id]->cogs ?? 0);
            $gp = $rev - $cogs;

            return [
                'branch_id' => $r->branch_id,
                'branch_name' => $r->branch_name,
                'gross_revenue' => round($rev, 2),
                'cogs' => round($cogs, 2),
                'gross_profit' => round($gp, 2),
                'gross_margin_pct' => $rev > 0 ? round(($gp / $rev) * 100, 1) : null,
            ];
        })
            ->sortByDesc('gross_profit')
            ->take($limit)
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function expensesByCategory(
        int $orgId,
        ?int $branchId,
        string $from,
        string $to,
        ?int $recordedByUserId = null,
    ): array {
        if (! Schema::hasTable('expenses')) {
            return [];
        }

        $query = DB::table('expenses as e')
            ->leftJoin('expense_groups as g', 'g.id', '=', 'e.expense_group_id')
            ->whereNull('e.deleted_at')
            ->whereDate('e.expense_date', '>=', $from)
            ->whereDate('e.expense_date', '<=', $to);

        if ($orgId && Schema::hasColumn('expenses', 'organization_id')) {
            $query->where('e.organization_id', $orgId);
        }
        if ($branchId !== null && Schema::hasColumn('expenses', 'branch_id')) {
            $query->where('e.branch_id', $branchId);
        }
        if ($recordedByUserId !== null && Schema::hasColumn('expenses', 'recorded_by')) {
            $query->where('e.recorded_by', $recordedByUserId);
        }

        return $query
            ->selectRaw('COALESCE(g.group_name, "Uncategorized") as category, ROUND(SUM(e.expense_amount), 2) as amount, COUNT(*) as expense_count')
            ->groupBy('category')
            ->orderByDesc('amount')
            ->get()
            ->map(fn ($r) => [
                'category' => $r->category,
                'amount' => (float) $r->amount,
                'expense_count' => (int) $r->expense_count,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function expenseLinesForUser(
        int $orgId,
        ?int $branchId,
        string $from,
        string $to,
        int $recordedByUserId,
    ): array {
        if (! Schema::hasTable('expenses') || ! Schema::hasColumn('expenses', 'recorded_by')) {
            return [];
        }

        $query = DB::table('expenses as e')
            ->leftJoin('expense_groups as g', 'g.id', '=', 'e.expense_group_id')
            ->whereNull('e.deleted_at')
            ->where('e.recorded_by', $recordedByUserId)
            ->whereDate('e.expense_date', '>=', $from)
            ->whereDate('e.expense_date', '<=', $to);

        if ($orgId && Schema::hasColumn('expenses', 'organization_id')) {
            $query->where('e.organization_id', $orgId);
        }
        if ($branchId !== null && Schema::hasColumn('expenses', 'branch_id')) {
            $query->where('e.branch_id', $branchId);
        }

        return $query
            ->orderByDesc('e.expense_date')
            ->limit(40)
            ->get([
                'e.expense_date',
                'e.description',
                'e.expense_amount',
                'g.group_name',
            ])
            ->map(fn ($r) => [
                'expense_date' => (string) $r->expense_date,
                'category' => (string) ($r->group_name ?: 'Uncategorized'),
                'description' => (string) ($r->description ?: '—'),
                'amount' => round((float) $r->expense_amount, 2),
            ])
            ->all();
    }

    /**
     * Mobile-rep expenses attributed to a user (mobile_route_expenses.user_id).
     *
     * @return list<array<string, mixed>>
     */
    protected function mobileRouteExpensesForUser(
        int $orgId,
        string $from,
        string $to,
        ?int $userId,
    ): array {
        if ($userId === null || $userId < 1 || ! Schema::hasTable('mobile_route_expenses')) {
            return [];
        }

        return DB::table('mobile_route_expenses')
            ->where('organization_id', $orgId)
            ->where('user_id', $userId)
            ->whereDate('expense_date', '>=', $from)
            ->whereDate('expense_date', '<=', $to)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->limit(40)
            ->get([
                'expense_date',
                'description',
                'expense_amount',
                'status',
            ])
            ->map(fn ($r) => [
                'expense_date' => (string) $r->expense_date,
                'description' => (string) ($r->description ?: '—'),
                'amount' => round((float) $r->expense_amount, 2),
                'status' => (string) ($r->status ?: '—'),
                'source' => 'mobile_route_expense',
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    protected function glCashBankBalances(int $orgId): array
    {
        if (! Schema::hasTable('chart_of_accounts') || ! Schema::hasTable('journal_entry_lines')) {
            return [];
        }

        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('account_code', 'like', '10%')
                    ->orWhere('account_code', 'like', '11%')
                    ->orWhere('account_name', 'like', '%cash%')
                    ->orWhere('account_name', 'like', '%bank%');
            })
            ->get(['id', 'account_code', 'account_name']);

        if ($accounts->isEmpty()) {
            return [];
        }

        $balances = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->whereIn('jel.account_id', $accounts->pluck('id'))
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, ROUND(SUM(jel.debit - jel.credit), 2) as balance')
            ->pluck('balance', 'account_id');

        return $accounts->map(fn ($a) => [
            'account_code' => $a->account_code,
            'account_name' => $a->account_name,
            'balance' => round((float) ($balances[$a->id] ?? 0), 2),
        ])->values()->all();
    }

    /** @return array<string, float|null> */
    protected function supplierPayablesTotal(int $orgId): array
    {
        if (! Schema::hasTable('suppliers')) {
            return ['total' => null, 'supplier_count' => 0];
        }

        $col = Schema::hasColumn('suppliers', 'current_balance') ? 'current_balance' : null;
        if ($col === null) {
            return ['total' => null, 'supplier_count' => 0];
        }

        $total = (float) DB::table('suppliers')
            ->where('organization_id', $orgId)
            ->where($col, '>', 0)
            ->sum($col);
        $count = (int) DB::table('suppliers')
            ->where('organization_id', $orgId)
            ->where($col, '>', 0)
            ->count();

        return ['total' => round($total, 2), 'supplier_count' => $count];
    }

    /**
     * @param  array<string, float|int|null>  $current
     * @param  array<string, float|int|null>  $previous
     * @return array<string, float|null>
     */
    protected function periodChange(array $current, array $previous): array
    {
        $keys = ['gross_revenue', 'gross_profit', 'net_profit', 'cogs', 'total_expenses'];
        $change = [];
        foreach ($keys as $key) {
            $cur = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);
            $change[$key.'_pct'] = $prev > 0 ? round((($cur - $prev) / $prev) * 100, 1) : null;
            $change[$key.'_delta'] = round($cur - $prev, 2);
        }

        return $change;
    }
}
