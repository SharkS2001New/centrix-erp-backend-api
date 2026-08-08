<?php

namespace App\Services\Ai\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extended insight data slices (debtors, tills, routes, exceptions, demand, etc.).
 *
 * @mixin \App\Services\Ai\AiInsightDataBuilder
 */
trait BuildsExtendedInsightSlices
{
    public const MAX_LIST = 40;

    /** @return array<string, mixed> */
    public function debtorsBriefSlice(Organization $organization, User $user, int $lookbackDays = 30): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();

        $open = DB::table('sales')
            ->leftJoin('customers', function ($join) use ($orgId) {
                $join->on('customers.customer_num', '=', 'sales.customer_num')
                    ->where('customers.organization_id', '=', $orgId);
            })
            ->where('sales.organization_id', $orgId)
            ->whereNotIn('sales.status', ['cancelled', 'draft', 'held', 'expired'])
            ->where(function ($q) {
                $q->where('sales.is_credit_sale', 1)
                    ->orWhereIn('sales.payment_status', ['unpaid', 'partial', 'partially_paid']);
            })
            ->whereRaw('(sales.order_total - COALESCE(sales.amount_paid, 0)) > 0.01')
            ->selectRaw('
                sales.customer_num,
                COALESCE(customers.customer_name, sales.customer_name_override, sales.customer_num) as customer_name,
                customers.credit_limit,
                customers.current_balance,
                customers.phone_number,
                COUNT(*) as open_orders,
                ROUND(SUM(GREATEST(sales.order_total - COALESCE(sales.amount_paid, 0), 0)), 2) as balance_due,
                MIN(DATE(COALESCE(sales.completed_at, sales.created_at))) as oldest_open_date,
                MAX(DATE(COALESCE(sales.completed_at, sales.created_at))) as latest_open_date
            ')
            ->groupBy(
                'sales.customer_num',
                'customers.customer_name',
                'sales.customer_name_override',
                'customers.credit_limit',
                'customers.current_balance',
                'customers.phone_number',
            )
            ->orderByDesc('balance_due')
            ->limit(self::MAX_LIST)
            ->get();

        $totalDue = round((float) $open->sum('balance_due'), 2);
        $top5 = $open->take(5);
        $top5Share = $totalDue > 0
            ? round(($top5->sum('balance_due') / $totalDue) * 100, 1)
            : 0.0;

        $volume = collect($this->topCustomerSales($orgId, $from, now()->toDateString(), 15));

        $callList = $top5->map(function ($row) {
            $due = (float) $row->balance_due;
            $ask = $due > 5000 ? round($due / 3, 0) : $due;

            return [
                'customer_num' => $row->customer_num,
                'customer_name' => $row->customer_name,
                'phone' => $row->phone_number,
                'balance_due' => $due,
                'suggested_ask' => $ask,
                'open_orders' => (int) $row->open_orders,
                'oldest_open_date' => $row->oldest_open_date,
                'href' => $row->customer_num
                    ? '/customers/'.rawurlencode((string) $row->customer_num)
                    : '/sales/orders/queues/pending_payment',
            ];
        })->values()->all();

        return [
            'type' => 'debtors_brief',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'total_balance_due' => $totalDue,
            'debtor_count' => $open->count(),
            'concentration_top5_pct' => $top5Share,
            'debtors' => $open->map(fn ($r) => [
                'customer_num' => $r->customer_num,
                'customer_name' => $r->customer_name,
                'credit_limit' => $r->credit_limit !== null ? (float) $r->credit_limit : null,
                'current_balance' => $r->current_balance !== null ? (float) $r->current_balance : null,
                'balance_due' => (float) $r->balance_due,
                'open_orders' => (int) $r->open_orders,
                'oldest_open_date' => $r->oldest_open_date,
                'latest_open_date' => $r->latest_open_date,
            ])->all(),
            'call_these_5' => $callList,
            'volume_customers' => $volume,
            'actions_hint' => [
                ['label' => 'AR aging', 'href' => '/reports/ar-aging'],
                ['label' => 'Top debtors', 'href' => '/reports/top-debtors'],
                ['label' => 'Pending payment queue', 'href' => '/sales/orders/queues/pending_payment'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function cashTillHealthSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->startOfDay();

        $sessions = [];
        if (Schema::hasTable('till_float_sessions')) {
            $sessions = DB::table('till_float_sessions as t')
                ->leftJoin('users as u', 'u.id', '=', 't.cashier_id')
                ->leftJoin('tills as ti', 'ti.id', '=', 't.till_id')
                ->where('t.organization_id', $orgId)
            ->where(function ($q) use ($from) {
                $q->where('t.opened_at', '>=', $from)
                    ->orWhere('t.closed_at', '>=', $from)
                    ->orWhere('t.session_date', '>=', $from->toDateString());
            })
            ->whereNotNull('t.closing_amount')
            ->orderByDesc('t.id')
            ->limit(self::MAX_LIST)
            ->get([
                't.id',
                't.till_id',
                'ti.till_name',
                't.cashier_id',
                'u.full_name as cashier_name',
                't.working_amount',
                't.expected_amount',
                't.closing_amount',
                't.cash_sales',
                't.status',
                't.opened_at',
                't.closed_at',
            ])
            ->map(function ($row) {
                $expected = (float) ($row->expected_amount ?? 0);
                $closing = (float) ($row->closing_amount ?? 0);
                $variance = round($closing - $expected, 2);

                return [
                    'id' => (int) $row->id,
                    'till' => $row->till_name ?: ('Till #'.$row->till_id),
                    'cashier' => $row->cashier_name ?: ('User #'.$row->cashier_id),
                    'working_amount' => round((float) ($row->working_amount ?? 0), 2),
                    'expected_amount' => $expected,
                    'closing_amount' => $closing,
                    'variance' => $variance,
                    'cash_sales' => round((float) ($row->cash_sales ?? 0), 2),
                    'status' => $row->status,
                    'closed_at' => $row->closed_at,
                ];
            })
            ->all();
        }

        $outliers = array_values(array_filter(
            $sessions,
            fn ($s) => abs((float) $s['variance']) >= 50,
        ));

        $mix = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) >= ?', [$from->toDateString()])
            ->selectRaw('
                ROUND(SUM(COALESCE(cash, 0)), 2) as cash_total,
                ROUND(SUM(COALESCE(mpesa_amount, 0)), 2) as mpesa_total,
                ROUND(SUM(COALESCE(equity_amount, 0) + COALESCE(kcb_amount, 0)), 2) as bank_total,
                ROUND(SUM(order_total), 2) as order_total
            ')
            ->first();

        $salesSettings = is_array($organization->module_settings['sales'] ?? null)
            ? $organization->module_settings['sales']
            : [];

        return [
            'type' => 'cash_till_health',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'blind_till_close' => (bool) ($salesSettings['blind_till_close'] ?? false),
            'sessions' => $sessions,
            'variance_outliers' => array_slice($outliers, 0, 15),
            'payment_mix' => [
                'cash' => (float) ($mix->cash_total ?? 0),
                'mpesa' => (float) ($mix->mpesa_total ?? 0),
                'bank' => (float) ($mix->bank_total ?? 0),
                'order_total' => (float) ($mix->order_total ?? 0),
            ],
            'actions_hint' => [
                ['label' => 'Till management', 'href' => '/sales/till-management'],
                ['label' => 'Daily sales', 'href' => '/reports/daily-sales'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function routeMobileDebriefSlice(Organization $organization, User $user, int $lookbackDays = 1): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();
        $to = now()->toDateString();

        $base = DB::table('sales')
            ->where('organization_id', $orgId)
            ->where('channel', 'mobile')
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, $to]);

        $booked = (clone $base)->whereNotIn('status', ['cancelled', 'draft', 'expired'])->count();
        $delivered = (clone $base)->whereIn('status', ['delivered', 'completed', 'paid'])->count();
        $unpaid = (clone $base)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('(order_total - COALESCE(amount_paid, 0)) > 0.01')
            ->selectRaw('COUNT(*) as c, ROUND(SUM(GREATEST(order_total - COALESCE(amount_paid, 0), 0)), 2) as due')
            ->first();

        $topSkus = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->where('s.organization_id', $orgId)
            ->where('s.channel', 'mobile')
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('si.product_code, COALESCE(p.product_name, si.product_code) as product_name, SUM(si.quantity) as qty, ROUND(SUM(si.amount), 2) as amount')
            ->groupBy('si.product_code', 'p.product_name')
            ->orderByDesc('amount')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'qty' => (float) $r->qty,
                'amount' => (float) $r->amount,
            ])
            ->all();

        $stalled = DB::table('sales')
            ->leftJoin('customers', function ($join) use ($orgId) {
                $join->on('customers.customer_num', '=', 'sales.customer_num')
                    ->where('customers.organization_id', '=', $orgId);
            })
            ->where('sales.organization_id', $orgId)
            ->where('sales.channel', 'mobile')
            ->whereIn('sales.status', ['booked', 'pending', 'pending_payment', 'unpaid', 'processed'])
            ->whereRaw('DATE(sales.created_at) BETWEEN ? AND ?', [$from, $to])
            ->orderBy('sales.created_at')
            ->limit(20)
            ->get([
                'sales.id',
                'sales.order_num',
                'sales.status',
                'sales.route_id',
                'sales.customer_num',
                'sales.customer_name_override',
                'customers.customer_name',
                'sales.order_total',
                'sales.amount_paid',
                'sales.created_at',
            ])
            ->map(fn ($r) => [
                'order_num' => (int) $r->order_num,
                'status' => $r->status,
                'route_id' => $r->route_id,
                'customer' => $r->customer_name ?: $r->customer_name_override ?: $r->customer_num,
                'balance_due' => round(max(0, (float) $r->order_total - (float) $r->amount_paid), 2),
                'created_at' => $r->created_at,
                'href' => '/sales/orders/'.$r->id,
            ])
            ->all();

        return [
            'type' => 'route_mobile_debrief',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'from_date' => $from,
            'to_date' => $to,
            'booked_orders' => $booked,
            'delivered_or_completed' => $delivered,
            'unpaid_on_route' => [
                'count' => (int) ($unpaid->c ?? 0),
                'balance_due' => (float) ($unpaid->due ?? 0),
            ],
            'top_skus' => $topSkus,
            'stalled_customers' => $stalled,
            'actions_hint' => [
                ['label' => 'Mobile orders', 'href' => '/sales/orders/queues/mobile'],
                ['label' => 'Loading sheets', 'href' => '/sales/loading-sheets'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function exceptionRadarSlice(Organization $organization, User $user, int $lookbackDays = 7): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();
        $prevFrom = now()->subDays($lookbackDays * 2)->toDateString();
        $prevTo = now()->subDays($lookbackDays + 1)->toDateString();

        $stock = $this->stockPulseSlice($organization, $user, $lookbackDays);
        $unpaidNow = $this->unpaidSnapshot($orgId);
        $unpaidPrev = $this->unpaidSnapshotAsOf($orgId, $prevFrom, $prevTo);

        $discountSpike = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.organization_id', $orgId)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->where('si.discount_given', '>', 0)
            ->selectRaw('ROUND(SUM(si.discount_given), 2) as discount_total, COUNT(*) as discounted_lines')
            ->first();

        $voids = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereIn('status', ['cancelled', 'void', 'expired'])
            // sales has no updated_at (Sale::UPDATED_AT = null); use cancel/expire timestamps.
            ->whereRaw(
                'DATE(COALESCE(cancelled_at, expired_at, created_at)) BETWEEN ? AND ?',
                [$from, now()->toDateString()],
            )
            ->selectRaw('status, COUNT(*) as c, ROUND(SUM(order_total), 2) as amount')
            ->groupBy('status')
            ->get()
            ->map(fn ($r) => [
                'status' => $r->status,
                'count' => (int) $r->c,
                'amount' => (float) $r->amount,
            ])
            ->all();

        $pendingApprovals = 0;
        if (Schema::hasTable('action_requests')) {
            $pendingApprovals = (int) DB::table('action_requests')
                ->where('organization_id', $orgId)
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->where('type', 'like', '%discount%')
                        ->orWhere('title', 'like', '%discount%');
                })
                ->count();
        }

        return [
            'type' => 'exception_radar',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'low_stock_count' => $stock['low_stock_count'] ?? 0,
            'low_stock_sample' => array_slice($stock['low_stock_items'] ?? [], 0, 10),
            'unpaid_now' => $unpaidNow,
            'unpaid_previous_period' => $unpaidPrev,
            'unpaid_spike' => ($unpaidPrev['balance_due'] ?? 0) > 0
                && ($unpaidNow['balance_due'] ?? 0) > ($unpaidPrev['balance_due'] * 1.25),
            'discounts' => [
                'discount_total' => (float) ($discountSpike->discount_total ?? 0),
                'discounted_lines' => (int) ($discountSpike->discounted_lines ?? 0),
                'pending_discount_approvals' => $pendingApprovals,
            ],
            'voids_cancels' => $voids,
            'actions_hint' => [
                ['label' => 'Low stock', 'href' => '/reports/low-stock'],
                ['label' => 'Pending payment', 'href' => '/sales/orders/queues/pending_payment'],
                ['label' => 'Discount approvals', 'href' => '/admin/approvals'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function explainScreenSlice(
        Organization $organization,
        User $user,
        string $screenKey,
        array $filters = [],
        array $rows = [],
        ?array $summary = null,
        ?string $question = null,
    ): array {
        $capped = array_slice($rows, 0, self::MAX_REPORT_ROWS);

        return [
            'type' => 'explain_screen',
            'screen_key' => $screenKey,
            'organization' => $organization->org_name ?? $organization->name,
            'filters' => $filters,
            'summary' => $summary,
            'question' => $question,
            'row_count' => count($rows),
            'rows_sample' => $capped,
            'note' => count($rows) > self::MAX_REPORT_ROWS
                ? 'Only the first '.self::MAX_REPORT_ROWS.' rows were included.'
                : null,
            'actions_hint' => $this->screenActionsHint($screenKey),
        ];
    }

    /** @return array<string, mixed> */
    public function productDemandSlice(
        Organization $organization,
        User $user,
        int $lookbackDays = 30,
        ?string $productCode = null,
        ?string $productQuery = null,
    ): array {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();
        $to = now()->toDateString();

        $movers = $this->topProductSales($orgId, $from, $to, 20);
        $focusCode = $productCode;
        if (! $focusCode && $productQuery) {
            $match = DB::table('products')
                ->where('organization_id', $orgId)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($productQuery) {
                    $q->where('product_code', 'like', '%'.$productQuery.'%')
                        ->orWhere('product_name', 'like', '%'.$productQuery.'%');
                })
                ->orderBy('product_name')
                ->first(['product_code', 'product_name', 'stock_in_shop', 'stock_in_store', 'reorder_point', 'last_cost_price']);
            $focusCode = $match?->product_code;
        }

        $buyers = [];
        $product = null;
        $velocity = null;
        if ($focusCode) {
            $product = DB::table('products')
                ->where('organization_id', $orgId)
                ->where('product_code', $focusCode)
                ->first(['product_code', 'product_name', 'stock_in_shop', 'stock_in_store', 'reorder_point', 'last_cost_price', 'unit_price']);

            $buyers = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->leftJoin('customers as c', function ($join) use ($orgId) {
                    $join->on('c.customer_num', '=', 's.customer_num')
                        ->where('c.organization_id', '=', $orgId);
                })
                ->where('s.organization_id', $orgId)
                ->where('si.product_code', $focusCode)
                ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
                ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, $to])
                ->selectRaw('
                    s.customer_num,
                    COALESCE(c.customer_name, s.customer_name_override, s.customer_num) as customer_name,
                    SUM(si.quantity) as qty,
                    ROUND(SUM(si.amount), 2) as amount,
                    COUNT(DISTINCT s.id) as orders
                ')
                ->groupBy('s.customer_num', 'c.customer_name', 's.customer_name_override')
                ->orderByDesc('amount')
                ->limit(20)
                ->get()
                ->map(fn ($r) => [
                    'customer_num' => $r->customer_num,
                    'customer_name' => $r->customer_name,
                    'qty' => (float) $r->qty,
                    'amount' => (float) $r->amount,
                    'orders' => (int) $r->orders,
                ])
                ->all();

            $soldQty = (float) collect($buyers)->sum('qty');
            $daily = $lookbackDays > 0 ? round($soldQty / $lookbackDays, 2) : 0;
            $onHand = (float) ($product->stock_in_shop ?? 0) + (float) ($product->stock_in_store ?? 0);
            $velocity = [
                'qty_sold' => $soldQty,
                'avg_daily_qty' => $daily,
                'stock_on_hand' => $onHand,
                'days_of_cover' => $daily > 0 ? round($onHand / $daily, 1) : null,
                'suggested_reorder_qty' => $daily > 0
                    ? max(0, (int) ceil(($daily * 14) - $onHand))
                    : (int) ($product->reorder_point ?? 0),
            ];
        }

        $dead = DB::table('products')
            ->where('organization_id', $orgId)
            ->whereNull('deleted_at')
            ->whereRaw('(COALESCE(stock_in_shop,0) + COALESCE(stock_in_store,0)) > 0')
            ->whereNotIn('product_code', collect($movers)->pluck('product_code')->filter()->all() ?: ['__none__'])
            ->orderByDesc(DB::raw('COALESCE(stock_in_shop,0) + COALESCE(stock_in_store,0)'))
            ->limit(15)
            ->get(['product_code', 'product_name', 'stock_in_shop', 'stock_in_store', 'unit_price'])
            ->map(fn ($p) => [
                'product_code' => $p->product_code,
                'product_name' => $p->product_name,
                'stock_on_hand' => (float) $p->stock_in_shop + (float) $p->stock_in_store,
                'unit_price' => (float) $p->unit_price,
            ])
            ->all();

        return [
            'type' => 'product_demand',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'product_query' => $productQuery,
            'focus_product' => $product ? [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'stock_in_shop' => (float) $product->stock_in_shop,
                'stock_in_store' => (float) $product->stock_in_store,
                'reorder_point' => $product->reorder_point,
                'unit_price' => (float) $product->unit_price,
            ] : null,
            'velocity' => $velocity,
            'buyers' => $buyers,
            'fast_movers' => $movers,
            'possible_dead_stock' => $dead,
            'actions_hint' => [
                [
                    'label' => $focusCode ? 'Orders with this product' : 'Sales by product',
                    'href' => $focusCode
                        ? '/sales/orders?q='.rawurlencode((string) $focusCode)
                        : '/reports/sales-by-product',
                ],
                ['label' => 'Create LPO', 'href' => '/lpo'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function customer360Slice(
        Organization $organization,
        User $user,
        string|int $customerNum,
        int $lookbackDays = 90,
    ): array {
        $lookbackDays = max(1, min(180, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();
        $num = is_numeric($customerNum) ? (int) $customerNum : $customerNum;

        $customer = DB::table('customers')
            ->where('organization_id', $orgId)
            ->where('customer_num', $num)
            ->first();

        $orders = DB::table('sales')
            ->where('organization_id', $orgId)
            ->where('customer_num', $num)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'order_num', 'order_total', 'amount_paid', 'payment_status', 'is_credit_sale', 'status', 'created_at', 'completed_at']);

        $mix = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->where('s.organization_id', $orgId)
            ->where('s.customer_num', $num)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->selectRaw('si.product_code, COALESCE(p.product_name, si.product_code) as product_name, SUM(si.quantity) as qty, ROUND(SUM(si.amount), 2) as amount')
            ->groupBy('si.product_code', 'p.product_name')
            ->orderByDesc('amount')
            ->limit(12)
            ->get()
            ->map(fn ($r) => [
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'qty' => (float) $r->qty,
                'amount' => (float) $r->amount,
            ])
            ->all();

        $paidOnTime = 0;
        $partialCount = 0;
        foreach ($orders as $o) {
            if (in_array($o->payment_status, ['partial', 'partially_paid', 'unpaid'], true)) {
                $partialCount++;
            }
            if ($o->payment_status === 'paid') {
                $paidOnTime++;
            }
        }

        $lastOrderAt = $orders->first()?->completed_at ?? $orders->first()?->created_at;
        $daysSince = $lastOrderAt ? now()->diffInDays(\Carbon\Carbon::parse($lastOrderAt)) : null;
        $limit = (float) ($customer->credit_limit ?? 0);
        $balance = (float) ($customer->current_balance ?? 0);

        return [
            'type' => 'customer_360',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'customer' => $customer ? [
                'customer_num' => $customer->customer_num,
                'customer_name' => $customer->customer_name,
                'phone' => $customer->phone_number ?? null,
                'credit_limit' => $limit,
                'current_balance' => $balance,
                'credit_utilization_pct' => $limit > 0 ? round(($balance / $limit) * 100, 1) : null,
            ] : ['customer_num' => $num],
            'orders_in_period' => $orders->count(),
            'paid_orders' => $paidOnTime,
            'open_or_partial_orders' => $partialCount,
            'days_since_last_order' => $daysSince,
            'churn_signal' => $daysSince !== null && $daysSince > 45 ? 'elevated' : 'normal',
            'reorder_signal' => $daysSince !== null && $daysSince <= 14 ? 'likely' : 'unclear',
            'purchase_mix' => $mix,
            'recent_orders' => $orders->take(10)->map(fn ($o) => [
                'order_num' => (int) $o->order_num,
                'total' => (float) $o->order_total,
                'paid' => (float) $o->amount_paid,
                'payment_status' => $o->payment_status,
                'is_credit' => (bool) $o->is_credit_sale,
                'status' => $o->status,
            ])->values()->all(),
            'actions_hint' => [
                ['label' => 'Open customer', 'href' => '/customers/'.rawurlencode((string) $num)],
                ['label' => 'Customer orders', 'href' => '/sales/orders?q='.rawurlencode((string) $num)],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function marginDiscountWatchdogSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();

        $belowCost = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->where('s.organization_id', $orgId)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->whereNotNull('p.last_cost_price')
            ->where('p.last_cost_price', '>', 0)
            ->whereColumn('si.selling_price', '<', 'p.last_cost_price')
            ->orderByDesc(DB::raw('(p.last_cost_price - si.selling_price) * si.quantity'))
            ->limit(25)
            ->get([
                's.order_num',
                's.cashier_id',
                'si.product_code',
                'p.product_name',
                'si.selling_price',
                'p.last_cost_price',
                'si.quantity',
                'si.discount_given',
            ])
            ->map(fn ($r) => [
                'order_num' => (int) $r->order_num,
                'product_code' => $r->product_code,
                'product_name' => $r->product_name,
                'selling_price' => (float) $r->selling_price,
                'cost' => (float) $r->last_cost_price,
                'qty' => (float) $r->quantity,
                'discount_given' => (float) $r->discount_given,
                'cashier_id' => $r->cashier_id,
            ])
            ->all();

        $byCashier = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('users as u', 'u.id', '=', 's.cashier_id')
            ->where('s.organization_id', $orgId)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->where('si.discount_given', '>', 0)
            ->selectRaw('s.cashier_id, COALESCE(u.full_name, u.username, s.cashier_id) as cashier_name, ROUND(SUM(si.discount_given), 2) as discount_total, COUNT(*) as lines')
            ->groupBy('s.cashier_id', 'u.full_name', 'u.username')
            ->orderByDesc('discount_total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'cashier_id' => $r->cashier_id,
                'cashier_name' => $r->cashier_name,
                'discount_total' => (float) $r->discount_total,
                'lines' => (int) $r->lines,
            ])
            ->all();

        $pending = 0;
        if (Schema::hasTable('action_requests')) {
            $pending = (int) DB::table('action_requests')
                ->where('organization_id', $orgId)
                ->where('status', 'pending')
                ->where(function ($q) {
                    $q->where('type', 'like', '%discount%')
                        ->orWhere('title', 'like', '%discount%');
                })
                ->count();
        }

        return [
            'type' => 'margin_discount_watchdog',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'below_cost_lines' => $belowCost,
            'discount_by_cashier' => $byCashier,
            'pending_discount_approvals' => $pending,
            'actions_hint' => [
                ['label' => 'Approvals', 'href' => '/admin/approvals'],
                ['label' => 'Sales by product', 'href' => '/reports/sales-by-product'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function procurementCompanionSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $stock = $this->stockPulseSlice($organization, $user, $lookbackDays);
        $orgId = (int) $organization->id;
        $suggestions = [];

        foreach (array_slice($stock['low_stock_items'] ?? [], 0, 20) as $item) {
            $code = $item['product_code'] ?? $item['sku'] ?? null;
            if (! $code) {
                continue;
            }
            $product = DB::table('products')
                ->where('organization_id', $orgId)
                ->where('product_code', $code)
                ->first(['product_code', 'product_name', 'supplier_id', 'reorder_point', 'stock_in_shop', 'stock_in_store', 'last_cost_price']);

            $supplier = null;
            if ($product?->supplier_id && Schema::hasTable('suppliers')) {
                $supplier = DB::table('suppliers')
                    ->where('organization_id', $orgId)
                    ->where('id', $product->supplier_id)
                    ->first(['id', 'supplier_name']);
            }

            $onHand = (float) ($product->stock_in_shop ?? 0) + (float) ($product->stock_in_store ?? 0);
            $reorder = (float) ($product->reorder_point ?? 0);
            $qty = max(1, (int) ceil(max($reorder * 2 - $onHand, $reorder)));

            $suggestions[] = [
                'product_code' => $code,
                'product_name' => $product->product_name ?? ($item['product_name'] ?? $code),
                'supplier_id' => $supplier->id ?? null,
                'supplier_name' => $supplier->supplier_name ?? null,
                'suggested_qty' => $qty,
                'urgency' => $onHand <= 0 ? 'critical' : ($onHand < $reorder ? 'high' : 'normal'),
                'stock_on_hand' => $onHand,
                'reorder_point' => $reorder,
                'est_unit_cost' => $product->last_cost_price !== null ? (float) $product->last_cost_price : null,
                'confirm_action' => 'draft_lpo_line',
                'href' => '/lpo',
            ];
        }

        return [
            'type' => 'procurement_companion',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'low_stock_count' => $stock['low_stock_count'] ?? 0,
            'fast_movers' => array_slice($stock['fast_movers'] ?? [], 0, 10),
            'lpo_draft_suggestions' => $suggestions,
            'actions_hint' => [
                ['label' => 'Open LPO', 'href' => '/lpo'],
                ['label' => 'Low stock report', 'href' => '/reports/low-stock'],
                ['label' => 'Suppliers', 'href' => '/suppliers'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function collectionsPlaybookSlice(Organization $organization, User $user, int $lookbackDays = 60): array
    {
        $brief = $this->debtorsBriefSlice($organization, $user, $lookbackDays);
        $orgId = (int) $organization->id;

        $aging = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('(order_total - COALESCE(amount_paid, 0)) > 0.01')
            ->selectRaw("
                CASE
                    WHEN DATEDIFF(CURDATE(), DATE(COALESCE(completed_at, created_at))) <= 30 THEN '0_30'
                    WHEN DATEDIFF(CURDATE(), DATE(COALESCE(completed_at, created_at))) <= 60 THEN '31_60'
                    WHEN DATEDIFF(CURDATE(), DATE(COALESCE(completed_at, created_at))) <= 90 THEN '61_90'
                    ELSE '90_plus'
                END as bucket,
                COUNT(*) as orders,
                ROUND(SUM(GREATEST(order_total - COALESCE(amount_paid, 0), 0)), 2) as balance_due
            ")
            ->groupBy('bucket')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->bucket => [
                'orders' => (int) $r->orders,
                'balance_due' => (float) $r->balance_due,
            ]])
            ->all();

        $playbook = collect($brief['call_these_5'] ?? [])->map(function ($row) {
            $due = (float) $row['balance_due'];
            $asks = [];
            if ($due > 0) {
                $asks[] = ['label' => 'Full balance', 'amount' => $due];
                if ($due > 1000) {
                    $asks[] = ['label' => 'Half now', 'amount' => round($due / 2, 0)];
                    $asks[] = ['label' => 'Installment (1/3)', 'amount' => round($due / 3, 0)];
                }
            }

            return [
                ...$row,
                'suggested_asks' => $asks,
                'script_hint' => 'Confirm outstanding KES '.number_format($due, 2).' and agree a pay-today amount.',
            ];
        })->all();

        return [
            'type' => 'collections_playbook',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'aging_buckets' => $aging,
            'total_balance_due' => $brief['total_balance_due'] ?? 0,
            'prioritized_calls' => $playbook,
            'concentration_top5_pct' => $brief['concentration_top5_pct'] ?? 0,
            'actions_hint' => $brief['actions_hint'] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function anomalyDetectionSlice(Organization $organization, User $user, int $lookbackDays = 7): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();

        $avg = (float) DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->avg('order_total');

        $threshold = max($avg * 3, 50000);
        $largeOrders = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->where('order_total', '>=', $threshold)
            ->orderByDesc('order_total')
            ->limit(15)
            ->get(['id', 'order_num', 'order_total', 'customer_num', 'customer_name_override', 'channel', 'created_at'])
            ->map(fn ($r) => [
                'order_num' => (int) $r->order_num,
                'order_total' => (float) $r->order_total,
                'customer' => $r->customer_name_override ?: $r->customer_num,
                'channel' => $r->channel,
                'created_at' => $r->created_at,
                'href' => '/sales/orders/'.$r->id,
            ])
            ->all();

        $afterHours = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->whereRaw('HOUR(COALESCE(completed_at, created_at)) NOT BETWEEN 6 AND 21')
            ->orderByDesc('id')
            ->limit(15)
            ->get(['id', 'order_num', 'order_total', 'created_at', 'completed_at', 'cashier_id'])
            ->map(fn ($r) => [
                'order_num' => (int) $r->order_num,
                'order_total' => (float) $r->order_total,
                'at' => $r->completed_at ?: $r->created_at,
                'cashier_id' => $r->cashier_id,
                'href' => '/sales/orders/'.$r->id,
            ])
            ->all();

        $multiBranch = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotNull('customer_num')
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->selectRaw('customer_num, COUNT(DISTINCT branch_id) as branches, COUNT(*) as orders, ROUND(SUM(order_total), 2) as amount')
            ->groupBy('customer_num')
            ->having('branches', '>', 1)
            ->orderByDesc('branches')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'customer_num' => $r->customer_num,
                'branches' => (int) $r->branches,
                'orders' => (int) $r->orders,
                'amount' => (float) $r->amount,
            ])
            ->all();

        $deepDiscount = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.organization_id', $orgId)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->where('si.discount_given', '>', 0)
            ->whereRaw('si.discount_given >= (si.selling_price * si.quantity * 0.25)')
            ->orderByDesc('si.discount_given')
            ->limit(15)
            ->get(['s.order_num', 'si.product_code', 'si.discount_given', 'si.selling_price', 'si.quantity'])
            ->map(fn ($r) => [
                'order_num' => (int) $r->order_num,
                'product_code' => $r->product_code,
                'discount_given' => (float) $r->discount_given,
                'line_value' => round((float) $r->selling_price * (float) $r->quantity, 2),
            ])
            ->all();

        return [
            'type' => 'anomaly_detection',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'avg_order_total' => round($avg, 2),
            'large_order_threshold' => round($threshold, 2),
            'unusual_large_orders' => $largeOrders,
            'after_hours_sales' => $afterHours,
            'multi_branch_customers' => $multiBranch,
            'deep_discounts' => $deepDiscount,
            'actions_hint' => [
                ['label' => 'Sales orders', 'href' => '/sales/orders'],
                ['label' => 'Approvals', 'href' => '/admin/approvals'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function forecastLightSlice(Organization $organization, User $user, int $lookbackDays = 30): array
    {
        $lookbackDays = max(14, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();

        $bySku = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->leftJoin('products as p', function ($join) use ($orgId) {
                $join->on('p.product_code', '=', 'si.product_code')
                    ->where('p.organization_id', '=', $orgId);
            })
            ->where('s.organization_id', $orgId)
            ->whereNotIn('s.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->selectRaw('si.product_code, COALESCE(p.product_name, si.product_code) as product_name, SUM(si.quantity) as qty')
            ->groupBy('si.product_code', 'p.product_name')
            ->orderByDesc('qty')
            ->limit(25)
            ->get()
            ->map(function ($r) use ($lookbackDays) {
                $daily = $lookbackDays > 0 ? ((float) $r->qty / $lookbackDays) : 0;

                return [
                    'product_code' => $r->product_code,
                    'product_name' => $r->product_name,
                    'qty_sold_period' => (float) $r->qty,
                    'run_rate_7d' => round($daily * 7, 1),
                    'run_rate_14d' => round($daily * 14, 1),
                    'run_rate_30d' => round($daily * 30, 1),
                ];
            })
            ->all();

        $byRoute = DB::table('sales')
            ->where('organization_id', $orgId)
            ->whereNotNull('route_id')
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->selectRaw('route_id, COUNT(*) as orders, ROUND(SUM(order_total), 2) as amount')
            ->groupBy('route_id')
            ->orderByDesc('amount')
            ->limit(15)
            ->get()
            ->map(function ($r) use ($lookbackDays) {
                $daily = $lookbackDays > 0 ? ((float) $r->amount / $lookbackDays) : 0;

                return [
                    'route_id' => $r->route_id,
                    'orders' => (int) $r->orders,
                    'amount' => (float) $r->amount,
                    'run_rate_7d' => round($daily * 7, 0),
                    'run_rate_14d' => round($daily * 14, 0),
                    'run_rate_30d' => round($daily * 30, 0),
                ];
            })
            ->all();

        return [
            'type' => 'forecast_light',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'method' => 'simple_daily_run_rate',
            'sku_forecasts' => $bySku,
            'route_forecasts' => $byRoute,
            'actions_hint' => [
                ['label' => 'Sales by product', 'href' => '/reports/sales-by-product'],
                ['label' => 'Stock on hand', 'href' => '/reports/stock-on-hand'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function branchTillBenchmarksSlice(Organization $organization, User $user, int $lookbackDays = 14): array
    {
        $lookbackDays = max(1, min(90, $lookbackDays));
        $orgId = (int) $organization->id;
        $from = now()->subDays($lookbackDays)->toDateString();

        $branches = DB::table('sales')
            ->leftJoin('branches', 'branches.id', '=', 'sales.branch_id')
            ->where('sales.organization_id', $orgId)
            ->whereNotIn('sales.status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('DATE(COALESCE(sales.completed_at, sales.created_at)) BETWEEN ? AND ?', [$from, now()->toDateString()])
            ->selectRaw('
                sales.branch_id,
                COALESCE(branches.branch_name, sales.branch_id) as branch_name,
                COUNT(*) as orders,
                ROUND(SUM(sales.order_total), 2) as sales_total,
                ROUND(SUM(COALESCE(sales.cash, 0)), 2) as cash,
                ROUND(SUM(COALESCE(sales.mpesa_amount, 0)), 2) as mpesa
            ')
            ->groupBy('sales.branch_id', 'branches.branch_name')
            ->orderByDesc('sales_total')
            ->limit(20)
            ->get()
            ->map(fn ($r) => [
                'branch_id' => $r->branch_id,
                'branch_name' => $r->branch_name,
                'orders' => (int) $r->orders,
                'sales_total' => (float) $r->sales_total,
                'cash' => (float) $r->cash,
                'mpesa' => (float) $r->mpesa,
            ])
            ->all();

        $tillVariance = [];
        if (Schema::hasTable('till_float_sessions')) {
            $tillVariance = DB::table('till_float_sessions as t')
                ->leftJoin('tills as ti', 'ti.id', '=', 't.till_id')
                ->where('t.organization_id', $orgId)
                ->where(function ($q) use ($lookbackDays) {
                    $from = now()->subDays($lookbackDays)->startOfDay();
                    $q->where('t.opened_at', '>=', $from)
                        ->orWhere('t.closed_at', '>=', $from)
                        ->orWhere('t.session_date', '>=', $from->toDateString());
                })
                ->whereNotNull('t.closing_amount')
                ->selectRaw('
                    t.till_id,
                    COALESCE(ti.till_name, t.till_id) as till_name,
                    COUNT(*) as closes,
                    ROUND(AVG(t.closing_amount - COALESCE(t.expected_amount, 0)), 2) as avg_variance,
                    ROUND(SUM(ABS(t.closing_amount - COALESCE(t.expected_amount, 0))), 2) as abs_variance_sum
                ')
                ->groupBy('t.till_id', 'ti.till_name')
                ->orderByDesc('abs_variance_sum')
                ->limit(15)
                ->get()
                ->map(fn ($r) => [
                    'till_id' => $r->till_id,
                    'till_name' => $r->till_name,
                    'closes' => (int) $r->closes,
                    'avg_variance' => (float) $r->avg_variance,
                    'abs_variance_sum' => (float) $r->abs_variance_sum,
                ])
                ->all();
        }

        return [
            'type' => 'branch_till_benchmarks',
            'organization' => $organization->org_name ?? $organization->name,
            'lookback_days' => $lookbackDays,
            'scope' => 'same_organization_only',
            'branches' => $branches,
            'till_variance' => $tillVariance,
            'actions_hint' => [
                ['label' => 'Branches', 'href' => '/admin/branches'],
                ['label' => 'Till management', 'href' => '/sales/till-management'],
            ],
        ];
    }

    /** @return array{order_count: int, balance_due: float} */
    protected function unpaidSnapshotAsOf(int $organizationId, string $from, string $to): array
    {
        $rows = DB::table('sales')
            ->where('organization_id', $organizationId)
            ->whereNotIn('status', ['cancelled', 'draft', 'held', 'expired'])
            ->whereRaw('(COALESCE(order_total, 0) - COALESCE(amount_paid, 0)) > 0.01')
            ->whereRaw('DATE(COALESCE(completed_at, created_at)) BETWEEN ? AND ?', [$from, $to])
            ->selectRaw('COUNT(*) as order_count, COALESCE(SUM(GREATEST(order_total - amount_paid, 0)), 0) as balance_due')
            ->first();

        return [
            'order_count' => (int) ($rows->order_count ?? 0),
            'balance_due' => round((float) ($rows->balance_due ?? 0), 2),
        ];
    }

    /** @return list<array{label: string, href: string}> */
    protected function screenActionsHint(string $screenKey): array
    {
        return match (true) {
            str_contains($screenKey, 'order') => [
                ['label' => 'All orders', 'href' => '/sales/orders'],
                ['label' => 'Pending payment', 'href' => '/sales/orders/queues/pending_payment'],
            ],
            str_contains($screenKey, 'customer') => [
                ['label' => 'Customers', 'href' => '/customers'],
                ['label' => 'AR aging', 'href' => '/reports/ar-aging'],
            ],
            str_contains($screenKey, 'report') => [
                ['label' => 'Reports hub', 'href' => '/reports'],
            ],
            default => [
                ['label' => 'Dashboard', 'href' => '/dashboard'],
            ],
        };
    }
}
