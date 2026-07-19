<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Auth\UserAccessService;
use App\Services\Catalog\ProductCatalogFilterService;
use App\Services\Catalog\ProductPriceSheetService;
use App\Services\Inventory\StockValuationService;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use App\Services\Erp\ErpContext;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\CentrixSalesScope;
use App\Support\AppTimezone;
use App\Support\EffectiveSaleDate;
use App\Support\SalesChannelLabels;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** Cashier/user lookup for report filters (no admin module required). */
    public function filterCashiers(Request $request)
    {
        $user = $request->user();
        $access = app(UserAccessService::class);
        $orgId = $access->organizationId($user, $request);
        abort_unless($orgId, 403);

        $query = User::query()
            ->whereNull('deleted_at')
            ->where('organization_id', $orgId);

        if (! $access->isOrgWide($user)) {
            $branchId = $access->branchId($user);
            if ($branchId !== null) {
                $query->where(function ($inner) use ($branchId) {
                    $inner->where('branch_id', $branchId)
                        ->orWhere('access_scope', 'org');
                });
            }
        }

        if ($request->filled('id')) {
            $row = (clone $query)
                ->where('id', (int) $request->input('id'))
                ->first(['id', 'full_name', 'username']);

            abort_unless($row, 404);

            return response()->json($row);
        }

        $q = trim((string) $request->input('q', ''));
        if ($q === '') {
            return response()->json(['data' => []]);
        }

        $query->where(function ($inner) use ($q) {
            $inner->where('full_name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")
                ->orWhere('username', 'like', "%{$q}%");
        });

        $perPage = min((int) $request->input('per_page', 50), 50);
        $rows = $query
            ->orderBy('full_name')
            ->limit($perPage)
            ->get(['id', 'full_name', 'username']);

        return response()->json(['data' => $rows]);
    }

    /** Report catalog for ERP clients (bootstrap UI). */
    public function catalog(Request $request)
    {
        $inventory = [
            ['key' => 'items-currently-in-stock', 'path' => '/inventory/stock', 'label' => 'Items currently in stock'],
            ['key' => 'low-stock', 'path' => '/reports/low-stock', 'label' => 'Low stock / reorder'],
            ['key' => 'stock-movement', 'path' => '/reports/stock-movement', 'label' => 'Stock ledger (transactions)'],
            ['key' => 'stock-chain', 'path' => '/reports/stock-chain', 'label' => 'Stock chain (receive → sell)'],
            ['key' => 'stock-valuation', 'path' => '/reports/stock-valuation', 'label' => 'Stock valuation'],
            ['key' => 'stock-reservations', 'path' => '/reports/stock-reservations', 'label' => 'Active cart reservations'],
            ['key' => 'stock-receipts', 'path' => '/reports/stock-receipts', 'label' => 'Purchase receipts'],
            ['key' => 'stock-transfers', 'path' => '/reports/stock-transfers', 'label' => 'Stock transfers'],
        ];

        if ($this->organizationHasMultipleBranches($request)) {
            $inventory[] = [
                'key' => 'branch-stock-transfers',
                'path' => '/reports/branch-stock-transfers',
                'label' => 'Inter-branch transfers',
            ];
        }

        $inventory = array_merge($inventory, [
            ['key' => 'open-lpo', 'path' => '/reports/open-lpo', 'label' => 'Open LPO lines (pending receive)'],
            ['key' => 'purchases-by-supplier', 'path' => '/reports/purchases-by-supplier', 'label' => 'Purchases by supplier'],
            ['key' => 'damages', 'path' => '/reports/damages', 'label' => 'Damages & write-offs'],
            ['key' => 'supplier-returns', 'path' => '/reports/supplier-returns', 'label' => 'Supplier returns'],
            ['key' => 'returns', 'path' => '/reports/returns', 'label' => 'Customer returns'],
            ['key' => 'price-list', 'path' => '/reports/price-list', 'label' => 'Price list & profit margins'],
        ]);

        return response()->json([
            'sales' => [
                ['key' => 'sales-by-product', 'path' => '/reports/sales-by-product', 'label' => 'Sales by product'],
                ['key' => 'sales-by-supplier', 'path' => '/reports/sales-by-supplier', 'label' => 'Sales by supplier'],
                ['key' => 'sales-by-user', 'path' => '/reports/sales-by-user', 'label' => 'Sales by user'],
                ['key' => 'sales-by-customer', 'path' => '/reports/sales-by-customer', 'label' => 'Sales by customer'],
                ['key' => 'sales-by-channel', 'path' => '/reports/sales-by-channel', 'label' => 'Sales by channel & payment status'],
                ['key' => 'daily-sales', 'path' => '/reports/daily-sales', 'label' => 'Daily sales summary'],
                ['key' => 'legacy-archive', 'path' => '/reports/legacy-archive', 'label' => 'Legacy sales archive (read-only)'],
                ['key' => 'mobile-route-sales', 'path' => '/reports/mobile-route-sales', 'label' => 'Route order sales'],
                ['key' => 'sales-pipeline', 'path' => '/reports/sales-pipeline', 'label' => 'Open orders pipeline'],
                ['key' => 'vat-collected', 'path' => '/reports/vat-collected', 'label' => 'VAT collected'],
                ['key' => 'category-sales', 'path' => '/reports/category-sales', 'label' => 'Sales by category'],
                ['key' => 'discount-summary', 'path' => '/reports/discount-summary', 'label' => 'Discounts given'],
                ['key' => 'payment-collection', 'path' => '/reports/payment-collection', 'label' => 'Payments by method'],
                ['key' => 'credit-outstanding', 'path' => '/reports/credit-outstanding', 'label' => 'Outstanding credit sales'],
                ['key' => 'eod-cashier', 'path' => '/reports/eod-cashier', 'label' => 'End of day (cashier)'],
                ['key' => 'eod-report', 'path' => '/reports/eod-report', 'label' => 'End of day report'],
            ],
            'inventory' => $inventory,
            'finance' => [
                ['key' => 'profit-loss', 'path' => '/reports/profit-loss', 'label' => 'Profit & loss (operational)'],
                ['key' => 'profit-loss-gl', 'path' => '/reports/profit-loss-gl', 'label' => 'Profit & loss (GL)'],
                ['key' => 'trial-balance', 'path' => '/reports/trial-balance', 'label' => 'Trial balance'],
                ['key' => 'balance-sheet', 'path' => '/reports/balance-sheet', 'label' => 'Balance sheet'],
                ['key' => 'cash-flow', 'path' => '/reports/cash-flow', 'label' => 'Cash flow'],
                ['key' => 'general-ledger', 'path' => '/reports/general-ledger', 'label' => 'General ledger'],
                ['key' => 'accounts-receivable', 'path' => '/reports/accounts-receivable', 'label' => 'Accounts receivable'],
                ['key' => 'accounts-payable', 'path' => '/reports/accounts-payable', 'label' => 'Accounts payable'],
                ['key' => 'ar-aging', 'path' => '/reports/ar-aging', 'label' => 'AR aging'],
                ['key' => 'top-debtors', 'path' => '/reports/top-debtors', 'label' => 'Top debtors'],
                ['key' => 'invoice-payments', 'path' => '/reports/invoice-payments', 'label' => 'Customer invoice payments'],
                ['key' => 'expenses', 'path' => '/reports/expenses', 'label' => 'Expenses by group'],
                ['key' => 'journal-register', 'path' => '/reports/journal-register', 'label' => 'Journal register'],
                ['key' => 'kra-receipts', 'path' => '/reports/kra-receipts', 'label' => 'KRA fiscal receipts'],
            ],
            'operations' => [
                ['key' => 'till-sessions', 'path' => '/reports/till-sessions', 'label' => 'Till / float sessions'],
                ['key' => 'audit-trail', 'path' => '/reports/audit-trail', 'label' => 'Audit trail'],
            ],
            'distribution' => [
                ['key' => 'mobile-route-sales', 'path' => '/reports/mobile-route-sales', 'label' => 'Route order sales'],
                ['key' => 'dispatch-trips', 'path' => '/reports/dispatch-trips', 'label' => 'Dispatch trips'],
                ['key' => 'trip-cash-settlement', 'path' => '/reports/trip-cash-settlement', 'label' => 'Trip cash settlement'],
                ['key' => 'pod-compliance', 'path' => '/reports/pod-compliance', 'label' => 'Proof of delivery'],
                ['key' => 'driver-deliveries', 'path' => '/reports/driver-deliveries', 'label' => 'Driver deliveries'],
            ],
            'hr' => [
                ['key' => 'leave-balance', 'path' => '/reports/leave-balance', 'label' => 'Leave balance'],
                ['key' => 'payroll-summary', 'path' => '/reports/payroll-summary', 'label' => 'Payroll summary'],
                ['key' => 'statutory-deductions', 'path' => '/reports/statutory-deductions', 'label' => 'Statutory deductions'],
                ['key' => 'bank-transfer', 'path' => '/reports/bank-transfer', 'label' => 'Bank transfer'],
                ['key' => 'staff-turnover', 'path' => '/reports/staff-turnover', 'label' => 'Staff turnover'],
                ['key' => 'headcount', 'path' => '/reports/headcount', 'label' => 'Headcount'],
                ['key' => 'contract-expiry', 'path' => '/reports/contract-expiry', 'label' => 'Contract expiry'],
                ['key' => 'hr-dashboard-kpi', 'path' => '/reports/hr-dashboard-kpi', 'label' => 'Workforce summary'],
            ],
            'customer' => [
                ['key' => 'customer-statement', 'path' => '/reports/customers/{customerNum}/statement', 'label' => 'Customer statement'],
            ],
            'filters' => [
                'branch_id', 'product_code', 'channel', 'cashier_id', 'customer_num',
                'supplier_id', 'route_name', 'sale_date', 'sale_day', 'period',
                'from_date', 'to_date', 'date_column', 'per_page', 'aging_bucket',
                'status', 'payment_status', 'lpo_no', 'organization_id', 'expense_group_id',
                'department_id', 'employment_status', 'employment_type', 'is_active',
                'payroll_run_id', 'period_code', 'days_until_expiry',
            ],
        ]);
    }

    /** KPIs and chart payloads for the reports hub dashboard. */
    public function dashboard(Request $request)
    {
        $data = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
            'branch_id' => 'nullable|integer',
        ]);

        $period = AppTimezone::reportPeriod(
            $data['from_date'] ?? null,
            $data['to_date'] ?? null,
        );
        $from = $period['from'];
        $to = $period['to'];
        $prevFrom = $period['prev_from'];
        $prevTo = $period['prev_to'];
        $branchId = $this->resolveReportBranchId($request, $data['branch_id'] ?? null);
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        $metricStatuses = app(OrderWorkflowService::class)->metricSaleStatuses();
        $dayExpr = EffectiveSaleDate::daySqlExpression();

        $salesBase = function (\Carbon\Carbon $start, \Carbon\Carbon $end) use ($branchId, $orgId, $metricStatuses) {
            $query = DB::table('sales')
                ->whereIn('status', $metricStatuses)
                ->where('archived', 0)
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

            EffectiveSaleDate::applyFromToDateFilter(
                $query,
                $start->toDateString(),
                $end->toDateString(),
            );

            return CentrixSalesScope::excludeLegacyMaterialized($query);
        };

        $totalSales = (float) $salesBase($from, $to)->sum('order_total');
        $prevTotalSales = (float) $salesBase($prevFrom, $prevTo)->sum('order_total');

        $plBase = fn (\Carbon\Carbon $start, \Carbon\Carbon $end) => DB::table('v_profit_loss_summary')
            ->where('period', '>=', $start->toDateString())
            ->where('period', '<=', $end->toDateString())
            ->when($orgId, fn ($q) => $this->scopeOrganizationBranches($q, $orgId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $grossProfit = (float) $plBase($from, $to)->sum('gross_profit');
        $prevGrossProfit = (float) $plBase($prevFrom, $prevTo)->sum('gross_profit');

        $receivables = (float) DB::table('customer_invoices')
            ->whereNull('deleted_at')
            ->where('balance_due', '>', 0)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('balance_due');

        $creditIssued = (float) CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sales')
                ->where('status', 'completed')
                ->where('archived', 0)
                ->where('is_credit_sale', 1)
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->whereDate('completed_at', '>=', $from->toDateString())
                ->whereDate('completed_at', '<=', $to->toDateString())
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId)),
        )->sum('order_total');

        $paymentsCollected = (float) DB::table('customer_invoice_payments as p')
            ->when($orgId, fn ($q) => $q->where('p.organization_id', $orgId))
            ->when($branchId, function ($q) use ($branchId) {
                $q->join('customer_invoices as ci', 'ci.id', '=', 'p.customer_invoice_id')
                    ->where('ci.branch_id', $branchId);
            }, function ($q) use ($orgId) {
                if ($orgId) {
                    $q->whereIn('p.customer_invoice_id', function ($sub) use ($orgId) {
                        $sub->select('ci.id')
                            ->from('customer_invoices as ci')
                            ->join('branches as b', 'b.id', '=', 'ci.branch_id')
                            ->where('b.organization_id', $orgId);
                    });
                }
            })
            ->whereDate('p.date_paid', '>=', $from->toDateString())
            ->whereDate('p.date_paid', '<=', $to->toDateString())
            ->sum('p.amount_paid');

        $prevReceivables = max(0, $receivables - $creditIssued + $paymentsCollected);

        // Inventory value = on-hand qty × effective unit cost (not retail price).
        $inventorySummary = app(StockValuationService::class)->summarize($orgId, $branchId);
        $shopInventoryValue = $inventorySummary['shop_value'];
        $storeInventoryValue = $inventorySummary['store_value'];
        $inventoryValue = $inventorySummary['value'];

        $receiptValue = (float) DB::table('stock_receipts')
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('SUM(units_received * COALESCE(cost_price, 0)) as total')
            ->value('total');

        $prevReceiptValue = (float) DB::table('stock_receipts')
            ->whereDate('created_at', '>=', $prevFrom->toDateString())
            ->whereDate('created_at', '<=', $prevTo->toDateString())
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('SUM(units_received * COALESCE(cost_price, 0)) as total')
            ->value('total');

        $prevInventory = max(0, $inventoryValue - $receiptValue + $prevReceiptValue);

        $dailyCurrent = $salesBase($from, $to)
            ->selectRaw("{$dayExpr} as day, SUM(order_total) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $dailyPrevious = $salesBase($prevFrom, $prevTo)
            ->selectRaw("{$dayExpr} as day, SUM(order_total) as total")
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->values();

        $salesTrend = [];
        $cursor = $from->copy();
        $idx = 0;
        while ($cursor->lte($to)) {
            $dayKey = $cursor->toDateString();
            $prevRow = $dailyPrevious[$idx] ?? null;
            $salesTrend[] = [
                'date' => $dayKey,
                'label' => $cursor->format('M j'),
                'current' => (float) ($dailyCurrent[$dayKey]->total ?? 0),
                'previous' => (float) ($prevRow->total ?? 0),
            ];
            $cursor->addDay();
            $idx++;
        }

        $topProducts = DB::table('v_sales_by_product')
            ->where('sale_date', '>=', $from->toDateString())
            ->where('sale_date', '<=', $to->toDateString())
            ->when($orgId, function ($q) use ($orgId) {
                if ($this->viewColumnExists('v_sales_by_product', 'organization_id')) {
                    $q->where('organization_id', $orgId);
                } else {
                    $this->scopeOrganizationBranches($q, $orgId);
                }
            })
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('product_code, product_name, SUM(total_revenue) as revenue')
            ->groupBy('product_code', 'product_name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'product_code' => $row->product_code,
                'product_name' => $row->product_name,
                'revenue' => (float) $row->revenue,
            ])
            ->values()
            ->all();

        $topTotal = array_sum(array_column($topProducts, 'revenue'));
        $topProducts = array_map(function ($row) use ($topTotal) {
            $row['share_pct'] = $topTotal > 0 ? round(($row['revenue'] / $topTotal) * 100, 1) : 0;

            return $row;
        }, $topProducts);

        $channelRows = $salesBase($from, $to)
            ->selectRaw('channel, SUM(order_total) as revenue, COUNT(*) as orders')
            ->groupBy('channel')
            ->orderByDesc('revenue')
            ->get();

        $salesByChannel = $this->aggregateSalesByChannel($channelRows);

        $payload = [
            'period' => [
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'previous_from_date' => $prevFrom->toDateString(),
                'previous_to_date' => $prevTo->toDateString(),
            ],
            'kpis' => [
                'total_sales' => [
                    'value' => $totalSales,
                    'change_pct' => $this->pctChange($totalSales, $prevTotalSales),
                ],
                'gross_profit' => [
                    'value' => $grossProfit,
                    'change_pct' => $this->pctChange($grossProfit, $prevGrossProfit),
                ],
                'receivables' => [
                    'value' => $receivables,
                    'change_pct' => $this->pctChange($receivables, $prevReceivables),
                ],
                'inventory_value' => [
                    'value' => $inventoryValue,
                    'shop_value' => $shopInventoryValue,
                    'store_value' => $storeInventoryValue,
                    'change_pct' => $this->pctChange($inventoryValue, $prevInventory),
                ],
            ],
            'sales_trend' => $salesTrend,
            'top_products' => $topProducts,
            'sales_by_channel' => $salesByChannel,
        ];

        return response()->json($payload);
    }

    protected function pctChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object{channel: ?string, revenue: mixed, orders: mixed}>  $channelRows
     * @return list<array{channel: string, channel_label: string, revenue: float, orders: int, share_pct: float}>
     */
    protected function aggregateSalesByChannel($channelRows): array
    {
        $grouped = [];
        foreach ($channelRows as $row) {
            $key = SalesChannelLabels::metricKey($row->channel);
            if (! isset($grouped[$key])) {
                $grouped[$key] = ['channel' => $key, 'revenue' => 0.0, 'orders' => 0];
            }
            $grouped[$key]['revenue'] += (float) $row->revenue;
            $grouped[$key]['orders'] += (int) $row->orders;
        }

        $rows = collect($grouped)->sortByDesc('revenue')->values();
        $total = (float) $rows->sum('revenue');

        return $rows->map(fn ($row) => [
            'channel' => $row['channel'],
            'channel_label' => SalesChannelLabels::label($row['channel']),
            'revenue' => $row['revenue'],
            'orders' => $row['orders'],
            'share_pct' => $total > 0 ? round(($row['revenue'] / $total) * 100, 1) : 0,
        ])->all();
    }

    public function salesByProduct(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_by_product', $this->filters($request), [
            'sale_date', 'branch_id', 'product_code', 'channel',
        ]));
    }

    public function salesBySupplier(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_by_supplier', $this->filters($request), [
            'sale_date', 'branch_id', 'supplier_id', 'channel', 'product_code',
        ]));
    }

    public function salesByUser(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_by_user', $this->filters($request), [
            'sale_date', 'branch_id', 'cashier_id', 'channel',
        ]));
    }

    public function salesByCustomer(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('v_sales_by_customer');
        $this->scopeReportQueryToOrganization($q, $request, 'v_sales_by_customer', [
            'customer_num', 'route_name',
        ]);

        foreach (['customer_num', 'route_name'] as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $q->where($col, $filters[$col]);
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_num', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if ($orgId && ! empty($filters['from_date']) && ! empty($filters['to_date'])) {
            $legacy = CentrixSalesScope::legacyExcludeSql('s');
            $statuses = CentrixSalesScope::reportPipelineStatuses();
            $q->whereExists(function ($sub) use ($filters, $orgId, $legacy, $statuses) {
                $sub->select(DB::raw('1'))
                    ->from('sales as s')
                    ->whereColumn('s.customer_num', 'v_sales_by_customer.customer_num')
                    ->where('s.organization_id', $orgId)
                    ->whereIn('s.status', $statuses)
                    ->where('s.archived', 0)
                    ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) >= ?', [$filters['from_date']])
                    ->whereRaw('DATE(COALESCE(s.completed_at, s.created_at)) <= ?', [$filters['to_date']])
                    ->whereRaw($legacy);
            });
        }

        return response()->json(
            $q->orderByDesc('total_purchased')
                ->paginate(min((int) ($filters['per_page'] ?? 20), 200)),
        );
    }

    public function salesByChannel(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_by_channel', $this->filters($request), [
            'sale_date', 'branch_id', 'channel', 'payment_status',
        ]));
    }

    public function dailySales(Request $request)
    {
        return response()->json($this->reportFromView('v_daily_sales', $this->filters($request), [
            'sale_day', 'branch_id', 'channel',
        ]));
    }

    public function routeSales(Request $request)
    {
        return response()->json($this->reportFromView('v_route_loading_summary', $this->filters($request), [
            'loading_date', 'route_name', 'channel',
        ]));
    }

    public function dispatchTrips(Request $request)
    {
        return response()->json($this->reportFromView('v_dispatch_trips_summary', $this->filters($request), [
            'scheduled_date', 'branch_id', 'route_name', 'driver_name', 'status',
        ]));
    }

    public function tripCashSettlement(Request $request)
    {
        return response()->json($this->reportFromView('v_trip_cash_settlement', $this->filters($request), [
            'scheduled_date', 'branch_id', 'route_name', 'driver_name', 'status',
        ]));
    }

    public function podCompliance(Request $request)
    {
        return response()->json($this->reportFromView('v_pod_compliance', $this->filters($request), [
            'capture_date', 'branch_id', 'route_name', 'driver_name',
        ]));
    }

    public function driverDeliveries(Request $request)
    {
        return response()->json($this->reportFromView('v_driver_deliveries', $this->filters($request), [
            'delivery_date', 'branch_id', 'driver_name', 'route_name',
        ]));
    }

    public function salesPipeline(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_pipeline', $this->filters($request), [
            'order_date', 'branch_id', 'channel', 'status', 'payment_status',
        ]));
    }

    public function vatCollected(Request $request)
    {
        return response()->json($this->reportFromView('v_vat_collected', $this->filters($request), [
            'sale_date', 'branch_id', 'channel',
        ]));
    }

    public function categorySales(Request $request)
    {
        return response()->json($this->reportFromView('v_category_sales', $this->filters($request), [
            'sale_date', 'branch_id', 'category_id', 'sub_category_id', 'product_code',
        ]));
    }

    public function discountSummary(Request $request)
    {
        return response()->json($this->reportFromView('v_discount_summary', $this->filters($request), [
            'sale_date', 'branch_id', 'channel',
        ]));
    }

    public function paymentCollection(Request $request)
    {
        return response()->json($this->reportFromView('v_payment_collection', $this->filters($request), [
            'payment_date', 'branch_id', 'channel', 'method_code',
        ]));
    }

    public function creditOutstanding(Request $request)
    {
        return response()->json($this->reportFromView('v_credit_outstanding', $this->filters($request), [
            'branch_id', 'channel', 'customer_num', 'status', 'payment_status',
        ]));
    }

    public function stockOnHand(Request $request)
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        return response()->json(
            app(\App\Services\Inventory\StockOnHandReportService::class)->paginate($request, $orgId),
        );
    }

    public function lowStock(Request $request)
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return response()->json(['data' => [], 'total' => 0]);
        }

        return response()->json(
            app(\App\Services\Inventory\LowStockReportService::class)->paginate($request, $orgId),
        );
    }

    public function stockMovement(Request $request)
    {
        $q = \App\Models\InventoryTransaction::query();
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if ($orgId) {
            $this->scopeOrganizationBranches($q, $orgId);
        }
        foreach (['branch_id', 'product_code', 'transaction_type', 'stock_location'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }
        if ($request->filled('from_date')) {
            $q->whereDate('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $q->whereDate('created_at', '<=', $request->input('to_date'));
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('product_code', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($product) => $product->where('product_name', 'like', "%{$search}%"));
            });
        }

        return response()->json(
            $q->with(['product:product_code,product_name,unit_id', 'product.unit'])
                ->orderByDesc('id')
                ->paginate(min((int) $request->input('per_page', 50), 200))
                ->through(function ($transaction) {
                    $payload = $transaction->toArray();
                    $payload['product_name'] = $transaction->product?->product_name;
                    $unit = $transaction->product?->unit;
                    if ($unit) {
                        $payload['uom_name'] = $unit->full_name;
                        $payload['conversion_factor'] = $unit->conversion_factor;
                        $payload['small_packaging_label'] = $unit->small_packaging_label;
                        $payload['middle_packaging_label'] = $unit->middle_packaging_label;
                        $payload['middle_factor'] = $unit->middle_factor;
                        $payload['uom_type'] = $unit->uom_type;
                    }

                    return $payload;
                }),
        );
    }

    public function stockChain(Request $request)
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'per_page' => min(max((int) $request->input('per_page', 20), 1), 200),
                'current_page' => max((int) $request->input('page', 1), 1),
                'last_page' => 1,
            ]);
        }

        return response()->json(
            app(\App\Services\Inventory\StockChainReportService::class)->paginate($request, $orgId),
        );
    }

    public function stockValuation(Request $request)
    {
        return response()->json($this->paginatedStockReport(
            $request,
            'v_stock_valuation',
            ['branch_id', 'product_code'],
        ));
    }

    public function inventoryValuationSummary(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|integer',
        ]);

        $access = app(UserAccessService::class);
        $orgId = $access->organizationId($request->user(), $request);
        if (! $orgId) {
            return response()->json([
                'shop_value' => 0,
                'store_value' => 0,
                'value' => 0,
                'branch_id' => null,
                'skus_in_stock' => 0,
                'skus_low' => 0,
                'skus_out' => 0,
                'total_available_units' => 0,
            ]);
        }

        $branchId = $this->resolveReportBranchId($request, $data['branch_id'] ?? null);
        if ($branchId !== null) {
            $access->assertBranchInOrganization($request->user(), $branchId, $request);
        }

        return response()->json(app(StockValuationService::class)->summarize($orgId, $branchId));
    }

    public function stockReservations(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_reservations_active', $this->filters($request), [
            'branch_id', 'product_code', 'stock_location',
        ]));
    }

    public function stockReceipts(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_receipts_detail', $this->filters($request), [
            'receipt_date', 'branch_id', 'product_code', 'stock_location',
        ]));
    }

    public function stockTransfers(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_transfers', $this->filters($request), [
            'transfer_date', 'branch_id', 'product_code', 'from_location', 'to_location',
        ]));
    }

    public function branchStockTransfers(Request $request)
    {
        if (! $this->organizationHasMultipleBranches($request)) {
            return response()->json([
                'data' => [],
                'current_page' => 1,
                'last_page' => 1,
                'per_page' => (int) ($request->input('per_page', 20)),
                'total' => 0,
                'message' => 'Inter-branch transfer reports are available when the organization has more than one branch.',
            ]);
        }

        return response()->json($this->reportFromView('v_branch_stock_transfers', $this->filters($request), [
            'transfer_date', 'from_branch_id', 'to_branch_id', 'product_code', 'from_location', 'to_location',
        ]));
    }

    public function openLpo(Request $request)
    {
        return response()->json($this->reportFromView(
            'v_open_lpo_lines',
            $this->filters($request),
            ['lpo_no', 'supplier_id', 'product_code', 'lpo_status_code'],
            function ($query) {
                $query->orderByDesc('lpo_no')
                    ->orderBy('product_name');
            },
        ));
    }

    public function profitLoss(Request $request)
    {
        $filters = $this->filters($request);
        $from = ! empty($filters['from_date']) ? (string) $filters['from_date'] : null;
        $to = ! empty($filters['to_date']) ? (string) $filters['to_date'] : null;
        $branchId = isset($filters['branch_id']) && $filters['branch_id'] !== ''
            ? (int) $filters['branch_id']
            : null;
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);

        // Date-bounded aggregates — avoid v_profit_loss_summary, which materializes
        // all-time sales/receipts/expenses before the period filter can apply.
        $salesQuery = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sales')
                ->where('status', 'completed')
                ->where('archived', 0),
        );
        $this->applySalesTenantScope($salesQuery, $orgId, $branchId);
        if ($from) {
            $salesQuery->whereDate('completed_at', '>=', $from);
        }
        if ($to) {
            $salesQuery->whereDate('completed_at', '<=', $to);
        }

        $sales = $salesQuery
            ->selectRaw('COALESCE(SUM(order_total), 0) as gross_revenue, COALESCE(SUM(total_vat), 0) as vat_collected')
            ->first();

        $grossRevenue = (float) ($sales->gross_revenue ?? 0);
        $vatCollected = (float) ($sales->vat_collected ?? 0);
        $netRevenue = $grossRevenue - $vatCollected;

        $cogsQuery = DB::table('stock_receipts');
        $this->applyBranchTenantScope($cogsQuery, $orgId, $branchId);
        if ($orgId && Schema::hasColumn('stock_receipts', 'organization_id')) {
            $cogsQuery->where('organization_id', $orgId);
        }
        if ($from) {
            $cogsQuery->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $cogsQuery->whereDate('created_at', '<=', $to);
        }
        $cogs = (float) $cogsQuery
            ->selectRaw('COALESCE(SUM(units_received * COALESCE(cost_price, 0)), 0) as total_cost')
            ->value('total_cost');

        $expenseQuery = DB::table('expenses')->whereNull('deleted_at');
        $this->applyBranchTenantScope($expenseQuery, $orgId, $branchId);
        if ($orgId && Schema::hasColumn('expenses', 'organization_id')) {
            $expenseQuery->where('organization_id', $orgId);
        }
        if ($from) {
            $expenseQuery->whereDate('expense_date', '>=', $from);
        }
        if ($to) {
            $expenseQuery->whereDate('expense_date', '<=', $to);
        }
        $totalExpenses = (float) $expenseQuery->sum('expense_amount');

        $grossProfit = $netRevenue - $cogs;
        $netProfit = $grossProfit - $totalExpenses;

        $row = [
            'period' => $from,
            'period_end' => $to,
            'branch_id' => $branchId,
            'branch_name' => null,
            'gross_revenue' => round($grossRevenue, 2),
            'vat_collected' => round($vatCollected, 2),
            'net_revenue' => round($netRevenue, 2),
            'cogs' => round($cogs, 2),
            'gross_profit' => round($grossProfit, 2),
            'total_expenses' => round($totalExpenses, 2),
            'net_profit' => round($netProfit, 2),
        ];

        return response()->json([
            'data' => [$row],
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 1,
            'total' => 1,
            'from' => 1,
            'to' => 1,
        ]);
    }

    public function eodCashier(Request $request)
    {
        return response()->json($this->reportFromView('v_eod_cashier_summary', $this->filters($request), [
            'sale_date', 'branch_id', 'cashier_id',
        ]));
    }

    /** Full end-of-day dashboard payload for a branch and date or month. */
    public function eodReport(Request $request)
    {
        $data = $request->validate([
            'sale_date' => 'required_without:sale_month|date',
            'sale_month' => 'required_without:sale_date|date_format:Y-m',
            'branch_id' => 'nullable|integer',
            'cashier_id' => 'nullable|integer',
        ]);

        $branchId = $data['branch_id'] ?? null;
        $cashierId = isset($data['cashier_id']) ? (int) $data['cashier_id'] : null;
        if ($cashierId <= 0) {
            $cashierId = null;
        }

        $access = app(UserAccessService::class);
        $orgId = $access->organizationId($request->user(), $request);
        if (! $branchId && $request->user() && ! $access->isOrgWide($request->user())) {
            $branchId = $access->branchId($request->user());
        }
        $branchId = $branchId ? (int) $branchId : null;

        $isMonthly = ! empty($data['sale_month']);
        if ($isMonthly) {
            $periodStart = Carbon::parse($data['sale_month'].'-01')->startOfDay();
            $periodEnd = $periodStart->copy()->endOfMonth()->endOfDay();
            $date = $periodStart->toDateString();
        } else {
            $periodStart = Carbon::parse($data['sale_date'])->startOfDay();
            $periodEnd = $periodStart->copy()->endOfDay();
            $date = $data['sale_date'];
        }

        $periodStartDate = $periodStart->toDateString();
        $periodEndDate = $periodEnd->toDateString();

        $salesBase = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sales')
                ->where('status', 'completed')
                ->where('archived', 0),
        );
        $this->applySalesTenantScope($salesBase, $orgId, $branchId);
        if ($isMonthly) {
            $salesBase->whereBetween('completed_at', [$periodStart, $periodEnd]);
        } else {
            $salesBase->whereDate('completed_at', $date);
        }
        if ($cashierId) {
            $salesBase->where('cashier_id', $cashierId);
        }

        $agg = (clone $salesBase)->selectRaw('
            COUNT(*) as transactions,
            COUNT(DISTINCT customer_num) as customers,
            COALESCE(SUM(order_total), 0) as gross_sales,
            COALESCE(SUM(total_vat), 0) as total_vat,
            COALESCE(SUM(order_discount), 0) as order_discounts,
            COALESCE(SUM(cash), 0) as cash_collected,
            COALESCE(SUM(mpesa_amount), 0) as mpesa_collected,
            COALESCE(SUM(equity_amount), 0) as equity_collected,
            COALESCE(SUM(kcb_amount), 0) as kcb_collected,
            COALESCE(SUM(CASE WHEN is_credit_sale = 1 THEN order_total ELSE 0 END), 0) as credit_sales,
            MIN(completed_at) as first_sale_at,
            MAX(completed_at) as last_sale_at
        ')->first();

        $lineDiscountQuery = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sale_items as si')
                ->join('sales as s', 'si.sale_id', '=', 's.id')
                ->where('s.status', 'completed')
                ->where('s.archived', 0)
                ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId)),
            's',
        );
        $this->applySalesTenantScope($lineDiscountQuery, $orgId, $branchId, 's');
        if ($isMonthly) {
            $lineDiscountQuery->whereBetween('s.completed_at', [$periodStart, $periodEnd]);
        } else {
            $lineDiscountQuery->whereDate('s.completed_at', $date);
        }
        $lineDiscounts = (float) $lineDiscountQuery->sum('si.discount_given');

        $itemsSoldQuery = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sale_items as si')
                ->join('sales as s', 'si.sale_id', '=', 's.id')
                ->where('s.status', 'completed')
                ->where('s.archived', 0)
                ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId)),
            's',
        );
        $this->applySalesTenantScope($itemsSoldQuery, $orgId, $branchId, 's');
        if ($isMonthly) {
            $itemsSoldQuery->whereBetween('s.completed_at', [$periodStart, $periodEnd]);
        } else {
            $itemsSoldQuery->whereDate('s.completed_at', $date);
        }
        $itemsSold = (float) $itemsSoldQuery->sum('si.quantity');

        $saleIds = (clone $salesBase)->pluck('id');
        $refunds = $saleIds->isEmpty()
            ? 0
            : (float) DB::table('returns')->whereIn('sale_id', $saleIds)->sum('amount');

        $voidedQuery = DB::table('sales')
            ->where('status', 'cancelled');
        $this->applySalesTenantScope($voidedQuery, $orgId, $branchId);
        $voidedQuery->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId));
        if ($isMonthly) {
            $voidedQuery->whereBetween('cancelled_at', [$periodStart, $periodEnd]);
        } else {
            $voidedQuery->whereDate('cancelled_at', $date);
        }
        $voided = $voidedQuery->count();

        $gross = (float) ($agg->gross_sales ?? 0);
        $headerVat = round((float) ($agg->total_vat ?? 0), 2);
        $lineVat = $saleIds->isEmpty()
            ? 0.0
            : round((float) DB::table('sale_items')->whereIn('sale_id', $saleIds)->sum('product_vat'), 2);
        $totalVat = max($headerVat, $lineVat);
        $totalDiscounts = (float) ($agg->order_discounts ?? 0) + $lineDiscounts;
        $netSales = max(0, $gross - $totalDiscounts - $refunds);
        $grossSalesExVat = max(0, round($gross - $totalVat, 2));
        $netSalesExVat = max(0, round($netSales - $totalVat, 2));

        $cash = (float) ($agg->cash_collected ?? 0);
        $mpesa = (float) ($agg->mpesa_collected ?? 0);
        $bank = (float) ($agg->equity_collected ?? 0) + (float) ($agg->kcb_collected ?? 0);

        $sessionQ = DB::table('till_float_sessions as tfs');
        $this->applyBranchTenantScope($sessionQ, $orgId, $branchId, 'tfs.branch_id');
        if ($isMonthly) {
            $sessionQ->whereBetween('tfs.session_date', [$periodStartDate, $periodEndDate]);
        } else {
            $sessionQ->whereDate('tfs.session_date', $date);
        }
        if ($cashierId) {
            $sessionQ->where('tfs.cashier_id', $cashierId);
        }
        $openingFloat = (float) (clone $sessionQ)->sum('working_amount');
        $cashMovementTotals = $this->sumSessionCashMovements(
            (clone $sessionQ)->select('tfs.cash_movements')->get()->all(),
        );
        $cashMovementsIn = $cashMovementTotals['in'];
        $cashMovementsOut = $cashMovementTotals['out'];

        $tillRowsQuery = DB::table('till_float_sessions as tfs')
            ->join('tills as t', 'tfs.till_id', '=', 't.id')
            ->join('users as u', 'tfs.cashier_id', '=', 'u.id')
            ->leftJoin(DB::raw('(
                SELECT float_session_id, COUNT(*) AS txn_count, SUM(order_total) AS gross
                FROM sales
                WHERE status = \'completed\'
                  AND '.CentrixSalesScope::legacyExcludeSql('sales').'
                GROUP BY float_session_id
            ) s'), 's.float_session_id', '=', 'tfs.id')
            ->when($cashierId, fn ($q) => $q->where('tfs.cashier_id', $cashierId));
        $this->applyBranchTenantScope($tillRowsQuery, $orgId, $branchId, 'tfs.branch_id');
        if ($isMonthly) {
            $tillRowsQuery->whereBetween('tfs.session_date', [$periodStartDate, $periodEndDate]);
        } else {
            $tillRowsQuery->whereDate('tfs.session_date', $date);
        }
        $tillRows = $tillRowsQuery
            ->select(
                't.till_number',
                't.till_name',
                'u.username as cashier',
                DB::raw('COALESCE(s.gross, 0) as gross_sales'),
                DB::raw('COALESCE(s.txn_count, 0) as transactions'),
                'tfs.working_amount as opening_float',
            )
            ->get()
            ->map(function ($row) {
                $row->till_name = $row->till_name ?? $row->till_number;

                return $row;
            });

        $cashierRowsQuery = CentrixSalesScope::excludeLegacyMaterialized(
            DB::table('sales as s')
                ->join('users as u', 's.cashier_id', '=', 'u.id')
                ->where('s.status', 'completed')
                ->where('s.archived', 0),
            's',
        );
        $this->applySalesTenantScope($cashierRowsQuery, $orgId, $branchId, 's');
        if ($isMonthly) {
            $cashierRowsQuery->whereBetween('s.completed_at', [$periodStart, $periodEnd]);
        } else {
            $cashierRowsQuery->whereDate('s.completed_at', $date);
        }
        $cashierRows = $cashierRowsQuery
            ->groupBy('s.cashier_id', 'u.username', 'u.full_name')
            ->orderBy('cashier')
            ->select(
                's.cashier_id',
                DB::raw('COALESCE(NULLIF(TRIM(u.full_name), ""), u.username) as cashier'),
                DB::raw('COUNT(*) as transactions'),
                DB::raw('COALESCE(SUM(s.order_total), 0) as gross_sales'),
                DB::raw('COALESCE(SUM(s.total_vat), 0) as total_vat'),
                DB::raw('COALESCE(SUM(s.cash), 0) as cash_collected'),
                DB::raw('COALESCE(SUM(s.mpesa_amount), 0) as mpesa_collected'),
                DB::raw('COALESCE(SUM(s.equity_amount), 0) + COALESCE(SUM(s.kcb_amount), 0) as bank_collected'),
            )
            ->get()
            ->map(function ($row) use ($date, $branchId, $orgId, $isMonthly, $periodStartDate, $periodEndDate) {
                $floatQuery = DB::table('till_float_sessions')
                    ->where('cashier_id', $row->cashier_id);
                if ($isMonthly) {
                    $floatQuery->whereBetween('session_date', [$periodStartDate, $periodEndDate]);
                } else {
                    $floatQuery->whereDate('session_date', $date);
                }
                $this->applyBranchTenantScope($floatQuery, $orgId, $branchId);
                $row->opening_float = (float) $floatQuery->sum('working_amount');

                return $row;
            });

        $expenseRowsQuery = DB::table('v_expenses_summary');
        $this->applyBranchTenantScope($expenseRowsQuery, $orgId, $branchId);
        if ($isMonthly) {
            $expenseRowsQuery->whereBetween('expense_date', [$periodStartDate, $periodEndDate]);
        } else {
            $expenseRowsQuery->where('expense_date', $date);
        }
        $expenseRows = $expenseRowsQuery
            ->select('group_name', DB::raw('SUM(total_amount) as amount'))
            ->groupBy('group_name')
            ->get();

        $totalExpenses = $expenseRows->sum(fn ($r) => (float) $r->amount);

        $sessionExpenseQ = DB::table('expenses as e')
            ->join('till_float_sessions as tfs', 'e.float_session_id', '=', 'tfs.id')
            ->whereNotNull('e.float_session_id')
            ->whereNull('e.deleted_at')
            ->when($cashierId, fn ($q) => $q->where('tfs.cashier_id', $cashierId));
        $this->applyBranchTenantScope($sessionExpenseQ, $orgId, $branchId, 'tfs.branch_id');
        if ($isMonthly) {
            $sessionExpenseQ->whereBetween('tfs.session_date', [$periodStartDate, $periodEndDate]);
        } else {
            $sessionExpenseQ->whereDate('tfs.session_date', $date);
        }
        $sessionExpenses = (float) $sessionExpenseQ->sum('e.expense_amount');

        $creditPaymentsQuery = DB::table('customer_invoice_payments as cip')
            ->join('customer_invoices as ci', 'ci.id', '=', 'cip.customer_invoice_id');
        if ($orgId) {
            $creditPaymentsQuery->where('cip.organization_id', $orgId);
        }
        $this->applyBranchTenantScope($creditPaymentsQuery, $orgId, $branchId, 'ci.branch_id');
        if ($isMonthly) {
            $creditPaymentsQuery->whereBetween('cip.date_paid', [$periodStartDate, $periodEndDate]);
        } else {
            $creditPaymentsQuery->whereDate('cip.date_paid', $date);
        }
        $creditPayments = (float) $creditPaymentsQuery->sum('cip.amount_paid');

        $closingDebtors = (float) DB::table('customers')
            ->whereNull('deleted_at')
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId), function ($q) use ($orgId) {
                if ($orgId) {
                    $this->scopeOrganizationBranches($q, $orgId);
                }
            })
            ->sum('current_balance');

        $creditSales = (float) ($agg->credit_sales ?? 0);
        // Match till X for till cash movements (safe drop / pay out / cash in). Session expenses stay separate.
        $netCashExpected = round($openingFloat + $cash - $cashMovementsOut + $cashMovementsIn, 2);
        $netSalesMinusFloat = max(0, round($netSales - $openingFloat, 2));
        $netPosition = $netCashExpected - $totalExpenses - $closingDebtors;

        $dailyBreakdown = null;
        if ($isMonthly) {
            $dailyBreakdown = CentrixSalesScope::excludeLegacyMaterialized(
                DB::table('sales')
                    ->where('status', 'completed')
                    ->where('archived', 0)
                    ->whereBetween('completed_at', [$periodStart, $periodEnd])
                    ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId)),
            );
            $this->applySalesTenantScope($dailyBreakdown, $orgId, $branchId);
            $dailyBreakdown = $dailyBreakdown
                ->selectRaw('
                    DATE(completed_at) as sale_date,
                    COUNT(*) as transactions,
                    COALESCE(SUM(order_total), 0) as gross_sales,
                    COALESCE(SUM(total_vat), 0) as total_vat,
                    COALESCE(SUM(cash), 0) as cash_collected
                ')
                ->groupBy(DB::raw('DATE(completed_at)'))
                ->orderBy('sale_date')
                ->get()
                ->map(fn ($row) => [
                    'sale_date' => (string) $row->sale_date,
                    'transactions' => (int) $row->transactions,
                    'gross_sales' => round((float) $row->gross_sales, 2),
                    'total_vat' => round((float) $row->total_vat, 2),
                    'cash_collected' => round((float) $row->cash_collected, 2),
                ])
                ->values()
                ->all();
        }

        $branchName = null;
        if ($branchId) {
            $branchName = DB::table('branches')->where('id', $branchId)->value('branch_name');
        }

        $cashierName = null;
        if ($cashierId) {
            $cashierName = DB::table('users')
                ->where('id', $cashierId)
                ->selectRaw('COALESCE(NULLIF(TRIM(full_name), ""), username) as name')
                ->value('name');
        }

        return response()->json([
            'report_mode' => $isMonthly ? 'monthly' : 'daily',
            'sale_date' => $date,
            'sale_month' => $isMonthly ? $data['sale_month'] : null,
            'period_start' => $periodStartDate,
            'period_end' => $periodEndDate,
            'branch_id' => $branchId,
            'branch_name' => $branchName,
            'cashier_id' => $cashierId,
            'cashier_name' => $cashierName,
            'summary' => [
                'gross_sales' => $gross,
                'gross_sales_ex_vat' => $grossSalesExVat,
                'transactions' => (int) ($agg->transactions ?? 0),
                'total_discounts' => $totalDiscounts,
                'total_refunds' => $refunds,
                'net_sales' => $netSales,
                'net_sales_ex_vat' => $netSalesExVat,
                'total_vat' => $totalVat,
                'opening_float' => $openingFloat,
                'net_sales_minus_float' => $netSalesMinusFloat,
                'cash_movements_in' => round($cashMovementsIn, 2),
                'cash_movements_out' => round($cashMovementsOut, 2),
                'net_cash_expected' => $netCashExpected,
                'session_expenses' => $sessionExpenses,
                'items_sold' => (int) round($itemsSold),
                'customers' => (int) ($agg->customers ?? 0),
                'voided_transactions' => (int) $voided,
                'average_sale_value' => ($agg->transactions ?? 0) > 0
                    ? round($netSales / (int) $agg->transactions, 2)
                    : 0,
                'start_time' => $agg->first_sale_at,
                'end_time' => $agg->last_sale_at,
            ],
            'payments' => [
                'cash' => $cash,
                'mpesa' => $mpesa,
                'bank' => $bank,
                'card' => 0,
            ],
            'tills' => $tillRows,
            'cashiers' => $cashierRows,
            'expenses' => $expenseRows,
            'total_expenses' => $totalExpenses,
            'session_expenses' => $sessionExpenses,
            'debtors' => [
                'opening' => null,
                'new_credit_sales' => $creditSales,
                'payments_received' => $creditPayments,
                'closing' => $closingDebtors,
            ],
            'net_position' => $netPosition,
            'daily_breakdown' => $dailyBreakdown,
        ]);
    }

    public function arAging(Request $request)
    {
        return response()->json($this->reportFromView('v_ar_aging', $this->filters($request), [
            'customer_num', 'aging_bucket',
        ]));
    }

    public function topDebtors(Request $request)
    {
        $filters = $this->filters($request);
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        $hasDateFilter = ! empty($filters['from_date']) || ! empty($filters['to_date']);

        $q = DB::table('customers as c')
            ->leftJoin('routes as r', 'c.route_id', '=', 'r.id')
            ->leftJoin('customer_invoices as ci', function ($join) use ($filters) {
                $join->on('ci.customer_num', '=', 'c.customer_num')
                    ->whereColumn('ci.organization_id', 'c.organization_id')
                    ->whereIn('ci.payment_status', [0, 1])
                    ->whereNull('ci.deleted_at');
                if (! empty($filters['from_date'])) {
                    $join->where('ci.invoice_date', '>=', $filters['from_date']);
                }
                if (! empty($filters['to_date'])) {
                    $join->where('ci.invoice_date', '<=', $filters['to_date']);
                }
            })
            ->whereNull('c.deleted_at')
            ->when($orgId, fn ($query) => $query->where('c.organization_id', $orgId));

        if (! empty($filters['customer_num'])) {
            $q->where('c.customer_num', $filters['customer_num']);
        }
        if (! empty($filters['route_name'])) {
            $q->where('r.route_name', $filters['route_name']);
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('c.customer_name', 'like', "%{$search}%")
                    ->orWhere('c.customer_num', 'like', "%{$search}%")
                    ->orWhere('c.phone_number', 'like', "%{$search}%");
            });
        }

        $invoiceBalanceSql = 'COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0)';
        $outstandingSql = $hasDateFilter
            ? $invoiceBalanceSql
            : "GREATEST(COALESCE(c.current_balance, 0), {$invoiceBalanceSql})";

        $q->groupBy(
            'c.organization_id',
            'c.customer_num',
            'c.customer_name',
            'c.phone_number',
            'r.route_name',
            'c.current_balance',
        )
            ->select([
                'c.organization_id',
                'c.customer_num',
                'c.customer_name',
                'c.phone_number',
                'r.route_name',
                DB::raw('COALESCE(c.current_balance, 0) as current_balance'),
                DB::raw('COUNT(DISTINCT ci.id) as open_invoices'),
                DB::raw("{$invoiceBalanceSql} as invoice_balance"),
                DB::raw("{$outstandingSql} as outstanding_balance"),
            ]);

        if ($hasDateFilter) {
            $q->havingRaw('COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0) > 0');
        } else {
            $q->havingRaw(
                'COALESCE(c.current_balance, 0) > 0 OR COALESCE(SUM(GREATEST(ci.invoice_total - ci.amount_paid, 0)), 0) > 0',
            );
        }

        $perPage = min((int) ($filters['per_page'] ?? 20), 200);

        return response()->json(
            $q->orderByRaw('outstanding_balance DESC')
                ->orderBy('c.customer_name')
                ->paginate($perPage),
        );
    }

    public function invoicePayments(Request $request)
    {
        $filters = $this->filters($request);

        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);

        $q = DB::table('customer_invoice_payments as cip')
            ->join('customer_invoices as ci', 'ci.id', '=', 'cip.customer_invoice_id')
            ->join('payment_methods as pm', 'pm.id', '=', 'cip.payment_method_id')
            ->join('users as u', 'u.id', '=', 'cip.received_by')
            ->leftJoin('customers as c', function ($join) use ($orgId) {
                $join->on('c.customer_num', '=', 'cip.customer_num');
                if ($orgId && Schema::hasColumn('customers', 'organization_id')) {
                    $join->where('c.organization_id', '=', $orgId);
                }
            })
            ->select([
                'cip.id as payment_id',
                'cip.customer_invoice_id',
                'cip.customer_num',
                'c.customer_name',
                'ci.invoice_number',
                'ci.branch_id',
                'cip.date_paid',
                'cip.amount_paid',
                'pm.method_name',
                'u.username as received_by',
                'cip.reference_number',
            ]);

        if (Schema::hasColumn('customer_invoice_payments', 'organization_id')) {
            $q->addSelect('cip.organization_id');
            if ($orgId) {
                $q->where('cip.organization_id', $orgId);
            }
        } elseif ($orgId) {
            $q->whereIn('cip.customer_num', function ($sub) use ($orgId) {
                $sub->select('customer_num')
                    ->from('customers')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
            });
        }

        if (Schema::hasColumn('customer_invoices', 'deleted_at')) {
            $q->whereNull('ci.deleted_at');
        }

        if (! empty($filters['branch_id'])) {
            $q->where('ci.branch_id', $filters['branch_id']);
        } elseif ($orgId) {
            $this->scopeOrganizationBranches($q, $orgId, 'ci.branch_id');
        }

        if (! empty($filters['customer_num'])) {
            $q->where('cip.customer_num', $filters['customer_num']);
        }

        if (! empty($filters['from_date'])) {
            $q->where('cip.date_paid', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $q->where('cip.date_paid', '<=', $filters['to_date']);
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('c.customer_name', 'like', "%{$search}%")
                    ->orWhere('cip.customer_num', 'like', "%{$search}%")
                    ->orWhere('ci.invoice_number', 'like', "%{$search}%")
                    ->orWhere('cip.reference_number', 'like', "%{$search}%");
            });
        }

        return response()->json(
            $q->orderByDesc('cip.date_paid')
                ->orderByDesc('cip.id')
                ->paginate(min((int) ($filters['per_page'] ?? 20), 200)),
        );
    }

    public function purchasesBySupplier(Request $request)
    {
        return response()->json($this->reportFromView(
            'v_purchases_by_supplier',
            $this->filters($request),
            ['supplier_id', 'lpo_no'],
            function ($query) {
                $query->orderBy('supplier_name')
                    ->orderBy('supplier_id')
                    ->orderByDesc('order_date')
                    ->orderBy('lpo_no')
                    ->orderBy('product_name');
            },
        ));
    }

    public function expenses(Request $request)
    {
        return response()->json($this->reportFromView('v_expenses_summary', $this->filters($request), [
            'expense_date', 'branch_id', 'expense_group_id',
        ]));
    }

    public function damages(Request $request)
    {
        return response()->json($this->reportFromView('v_damages_summary', $this->filters($request), [
            'damage_date', 'branch_id', 'product_code', 'stock_location',
        ]));
    }

    public function supplierReturns(Request $request)
    {
        return response()->json($this->reportFromView('v_supplier_returns_detail', $this->filters($request), [
            'return_date', 'branch_id', 'supplier_id', 'product_code',
        ]));
    }

    public function kraReceipts(Request $request)
    {
        return response()->json($this->reportFromView('v_kra_receipts', $this->filters($request), [
            'receipt_date', 'branch_id', 'channel', 'status',
        ]));
    }

    public function journalRegister(Request $request)
    {
        return response()->json($this->reportFromView('v_journal_register', $this->filters($request), [
            'entry_date', 'branch_id', 'status', 'reference_type',
        ]));
    }

    public function tillSessions(Request $request)
    {
        return response()->json($this->reportFromView('v_till_session_summary', $this->filters($request), [
            'session_date', 'branch_id', 'cashier_id', 'status', 'till_id',
        ]));
    }

    public function payrollSummary(Request $request)
    {
        return response()->json($this->reportFromView('v_payroll_summary', $this->filters($request), [
            'organization_id', 'status', 'period_code',
        ]));
    }

    public function auditTrail(Request $request)
    {
        $filters = $this->filters($request);
        $q = DB::table('audit_logs');
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if ($orgId && Schema::hasColumn('audit_logs', 'organization_id')) {
            $q->where('organization_id', $orgId);
        }

        foreach (['user_id', 'branch_id', 'table_name', 'action'] as $col) {
            if (! empty($filters[$col])) {
                $q->where($col, $filters[$col]);
            }
        }
        if (! empty($filters['from_date'])) {
            $q->where('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $q->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        return response()->json(
            $q->orderByDesc('id')->paginate(min((int) ($filters['per_page'] ?? 50), 200))
        );
    }

    public function priceList(Request $request)
    {
        $filters = $this->filters($request);
        $perPage = min(max((int) ($filters['per_page'] ?? 50), 1), 200);
        $page = max((int) $request->input('page', 1), 1);

        return response()->json(
            $this->buildPriceList($request, $filters, $page, $perPage)
        );
    }

    /**
     * Rich price sheet rows (retail/dozens/wholesale) — preferred over client product crawls.
     */
    public function productPriceSheet(Request $request)
    {
        $filters = $this->filters($request);
        $perPage = min(max((int) ($filters['per_page'] ?? 200), 1), 200);
        $page = max((int) $request->input('page', 1), 1);
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);

        $query = DB::table('products as p')
            ->leftJoin('uoms as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('retail_package_settings as r', 'p.product_code', '=', 'r.product_code')
            ->leftJoin('sub_categories as sc', 'p.subcategory_id', '=', 'sc.id')
            ->leftJoin('categories as c', 'sc.category_id', '=', 'c.id')
            ->whereNull('p.deleted_at')
            ->where('p.unit_price', '>', 0)
            ->select([
                'p.product_code',
                'p.product_name',
                'p.unit_price',
                'p.last_cost_price',
                'p.sell_on_retail',
                'p.subcategory_id',
                'p.unit_id',
                'p.stock_in_shop',
                'p.stock_in_store',
                'p.reorder_point',
                'u.uom_type',
                'u.full_name as uom_full_name',
                'u.conversion_factor',
                'u.middle_factor',
                'u.small_packaging_label',
                'u.measure_name',
                'r.max_qty_measure',
                'r.markup_price',
                'r.wholesale_qty_measure',
                'r.wholesale_markup_price',
                'r.min_uom_measure',
                'r.pricing_tiers',
                'sc.subcategory_name',
                'c.category_name',
            ])
            ->orderBy('p.product_name');

        if ($orgId) {
            $query->where('p.organization_id', $orgId);
        }

        if (! empty($filters['branch_id'])) {
            $query->where(function ($branchQuery) use ($filters) {
                $branchQuery->where('p.branch_id', $filters['branch_id'])
                    ->orWhereNull('p.branch_id');
            });
        }

        if ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            $query->where('p.subcategory_id', $subcategoryId);
        }

        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($inner) use ($q) {
                $inner->where('p.product_name', 'like', "%{$q}%")
                    ->orWhere('p.product_code', 'like', "%{$q}%");
            });
        }

        $sheet = app(ProductPriceSheetService::class);
        $retailPricingEnabled = ! $request->boolean('wholesale_only');
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function ($row) use ($sheet, $retailPricingEnabled) {
            $uom = (object) [
                'conversion_factor' => $row->conversion_factor,
                'middle_factor' => $row->middle_factor,
                'small_packaging_label' => $row->small_packaging_label,
                'uom_type' => $row->uom_type,
                'full_name' => $row->uom_full_name,
                'measure_name' => $row->measure_name,
            ];
            $retail = [
                'max_qty_measure' => $row->max_qty_measure,
                'markup_price' => $row->markup_price,
                'wholesale_qty_measure' => $row->wholesale_qty_measure,
                'wholesale_markup_price' => $row->wholesale_markup_price,
                'min_uom_measure' => $row->min_uom_measure,
                'pricing_tiers' => $row->pricing_tiers,
            ];
            $built = $sheet->buildRow(
                $row,
                $uom,
                $retail,
                (string) ($row->subcategory_name ?? 'Uncategorized'),
                (string) ($row->category_name ?? 'Uncategorized'),
                $retailPricingEnabled,
            );
            $shop = (float) ($row->stock_in_shop ?? 0);
            $store = (float) ($row->stock_in_store ?? 0);
            $built['stock_qty'] = $shop + $store;
            $built['reorder_point'] = (float) ($row->reorder_point ?? 0);

            return $built;
        });

        return response()->json($paginator);
    }

    public function customerStatement(Request $request, int $customerNum)
    {
        return response()->json($this->buildCustomerStatement($request, $customerNum));
    }

    public function returns(Request $request)
    {
        $filters = $this->filters($request);
        if (empty($filters['date_column'])) {
            $filters['date_column'] = 'return_date';
        }

        return response()->json($this->reportFromView('v_customer_returns_detail', $filters, [
            'branch_id',
        ]));
    }

    protected function filters(Request $request): array
    {
        $filters = $request->only([
            'branch_id', 'product_code', 'channel', 'cashier_id', 'customer_num',
            'supplier_id', 'route_name', 'sale_date', 'sale_day', 'period',
            'from_date', 'to_date', 'date_column', 'per_page', 'aging_bucket',
            'status', 'payment_status', 'lpo_no', 'organization_id', 'expense_group_id',
            'order_date', 'loading_date', 'receipt_date', 'return_date', 'damage_date',
            'scheduled_date', 'capture_date', 'delivery_date',
            'transfer_date', 'payment_date', 'entry_date', 'session_date', 'method_code',
            'stock_location', 'from_location', 'to_location', 'from_branch_id', 'to_branch_id', 'lpo_status_code',
            'category_id', 'sub_category_id', 'till_id', 'reference_type', 'user_id',
            'table_name', 'action', 'transaction_type', 'location',
        ]);

        $user = $request->user();
        if ($user && empty($filters['branch_id'])) {
            $access = app(UserAccessService::class);
            if (! $access->isOrgWide($user)) {
                $branchId = $access->branchId($user);
                if ($branchId !== null) {
                    $filters['branch_id'] = $branchId;
                }
            }
        }

        return $filters;
    }

    protected function reportFromView(string $view, array $filters, array $allowedCols, ?callable $orderBy = null)
    {
        $request = request();
        $q = DB::table($view);
        $this->scopeReportQueryToOrganization($q, $request, $view, $allowedCols);

        $filterColumns = $allowedCols;
        if ($this->viewColumnExists($view, 'branch_id') && ! in_array('branch_id', $filterColumns, true)) {
            $filterColumns[] = 'branch_id';
        }

        foreach ($filterColumns as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $q->where($col, $filters[$col]);
            }
        }
        if (! empty($filters['from_date']) && ! empty($filters['date_column'])) {
            $dateColumn = $filters['date_column'];
            if ($this->viewColumnExists($view, $dateColumn)) {
                $q->where($dateColumn, '>=', $filters['from_date']);
            }
        }
        if (! empty($filters['to_date']) && ! empty($filters['date_column'])) {
            $dateColumn = $filters['date_column'];
            if ($this->viewColumnExists($view, $dateColumn)) {
                $q->where($dateColumn, '<=', $filters['to_date']);
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $searchable = array_values(array_filter(
                [
                    'product_name', 'product_code', 'customer_name', 'customer_num',
                    'supplier_name', 'cashier_name', 'invoice_number', 'reference_number',
                ],
                fn ($col) => $this->viewColumnExists($view, $col),
            ));
            if ($searchable !== []) {
                $q->where(function ($inner) use ($search, $searchable) {
                    foreach ($searchable as $i => $col) {
                        $method = $i === 0 ? 'where' : 'orWhere';
                        $inner->{$method}($col, 'like', "%{$search}%");
                    }
                });
            }
        }

        $this->applyProductSubcategoryFilter($q, $request, $view);

        if ($orderBy) {
            $orderBy($q);
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 20), 200));
    }

    protected function applyProductSubcategoryFilter($query, Request $request, string $view): void
    {
        if (! $subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            return;
        }

        if ($this->viewColumnExists($view, 'sub_category_id')) {
            $query->where('sub_category_id', $subcategoryId);

            return;
        }

        if (! $this->viewColumnExists($view, 'product_code')) {
            return;
        }

        $query->whereIn('product_code', function ($sub) use ($subcategoryId) {
            $sub->select('p.product_code')
                ->from('products as p')
                ->where('p.subcategory_id', $subcategoryId)
                ->whereNull('p.deleted_at');
        });
    }

    /**
     * @param  callable(\App\Models\Organization, ?\Carbon\Carbon, ?\Carbon\Carbon, int, int): array{data: list<array<string, mixed>>, meta: array<string, mixed>}  $legacyRowsProvider
     */
    protected function withLegacyArchiveRows(Request $request, $paginator, callable $legacyRowsProvider)
    {
        $org = $this->erp->resolveOrganization($request);
        $archive = app(LegacyArchiveReader::class);

        if (! $archive->isAvailable($org)) {
            return response()->json(array_merge($paginator->toArray(), [
                'legacy_archive' => [
                    'available' => false,
                    'message' => 'Legacy archive is not enabled or not reachable for this organization.',
                ],
            ]));
        }

        $from = $request->filled('from_date')
            ? AppTimezone::parseDateStart((string) $request->input('from_date'))
            : null;
        $to = $request->filled('to_date')
            ? AppTimezone::parseDateEnd((string) $request->input('to_date'))
            : null;

        $legacyPage = max((int) $request->input('legacy_page', $request->input('page', 1)), 1);
        $legacyPerPage = min(max((int) $request->input('per_page', 20), 1), 200);

        $payload = $paginator->toArray();
        $label = app(OrganizationLegacyArchiveService::class)->forOrganization($org)['label'] ?? 'LightStores archive';

        if (! $from || ! $to) {
            $payload['legacy_archive'] = [
                'available' => true,
                'label' => $label,
                'requires_date_range' => true,
                'message' => 'Provide from_date and to_date to load legacy archive rows.',
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $legacyPerPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];

            return response()->json($payload);
        }

        if (! $archive->shouldMergeForRange($org, $from, $to)) {
            $payload['legacy_archive'] = [
                'available' => true,
                'label' => $label,
                'cutover_date' => $archive->cutoverDate($org)?->toDateString(),
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => $legacyPerPage,
                    'total' => 0,
                    'last_page' => 1,
                ],
            ];

            return response()->json($payload);
        }

        $legacyResult = $legacyRowsProvider($org, $from, $to, $legacyPage, $legacyPerPage);
        $payload['legacy_archive'] = [
            'available' => true,
            'label' => $label,
            'cutover_date' => $archive->cutoverDate($org)?->toDateString(),
            'data' => $legacyResult['data'],
            'meta' => $legacyResult['meta'],
        ];

        return response()->json($payload);
    }

    /** @param list<string> $allowedCols */
    protected function paginatedStockReport(Request $request, string $view, array $allowedCols)
    {
        $filters = $this->filters($request);
        $q = DB::table($view);
        $this->scopeReportQueryToOrganization($q, $request, $view, $allowedCols);

        foreach ($allowedCols as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $q->where($col, $filters[$col]);
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('product_code', 'like', "%{$search}%")
                    ->orWhere('product_name', 'like', "%{$search}%");
            });
        }

        if ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            $q->whereIn('product_code', function ($sub) use ($subcategoryId) {
                $sub->select('p.product_code')
                    ->from('products as p')
                    ->where('p.subcategory_id', $subcategoryId)
                    ->whereNull('p.deleted_at');
            });
        }

        if ($location = (string) $request->input('location', '')) {
            if ($location === 'shop') {
                $q->where('shop_quantity', '>', 0);
            } elseif ($location === 'store') {
                $q->where('store_quantity', '>', 0);
            }
        }

        if ($request->boolean('in_stock_only') && $this->viewColumnExists($view, 'total_qty')) {
            $q->where('total_qty', '>', 0);
        }

        $perPage = min((int) $request->input('per_page', 25), 200);

        return $q->orderBy('product_name')->paginate($perPage);
    }

    protected function buildCustomerStatement(Request $request, int $customerNum): array
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        $invoiceService = app(CustomerInvoiceService::class);

        $customerQuery = Customer::query()
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at');
        if ($orgId) {
            $customerQuery->where('organization_id', $orgId);
        }
        $customer = $customerQuery->firstOrFail();

        $branchName = DB::table('branches')
            ->where('id', $customer->branch_id)
            ->value('branch_name');

        $routeName = $customer->route_id
            ? DB::table('routes')->where('id', $customer->route_id)->value('route_name')
            : null;

        $invoices = DB::table('customer_invoices')
            ->where('customer_num', $customerNum)
            ->when($orgId && Schema::hasColumn('customer_invoices', 'organization_id'), fn ($q) => $q->where('organization_id', $orgId))
            ->whereNull('deleted_at')
            ->orderBy('invoice_date')
            ->get();

        $payments = DB::table('customer_invoice_payments')
            ->where('customer_num', $customerNum)
            ->when($orgId && Schema::hasColumn('customer_invoice_payments', 'organization_id'), fn ($q) => $q->where('organization_id', $orgId))
            ->orderBy('date_paid')
            ->get();

        $creditNotes = collect();
        if (Schema::hasTable('credit_notes')) {
            $creditQuery = DB::table('credit_notes as cn')
                ->where('cn.customer_num', $customerNum)
                ->when($orgId, fn ($q) => $q->where('cn.organization_id', $orgId))
                ->orderBy('cn.credit_date')
                ->orderBy('cn.id');

            if (Schema::hasTable('customer_returns') && Schema::hasColumn('customer_returns', 'return_kind')) {
                $creditQuery
                    ->leftJoin('customer_returns as cr', 'cr.id', '=', 'cn.customer_return_id')
                    ->where(function ($q) {
                        $q->whereNull('cr.id')
                            ->orWhereNull('cr.return_kind')
                            ->orWhere('cr.return_kind', '!=', 'pos_edit');
                    })
                    ->select([
                        'cn.id',
                        'cn.credit_note_no',
                        'cn.customer_return_id',
                        'cn.sale_id',
                        'cn.customer_num',
                        'cn.credit_date',
                        'cn.total_amount',
                        'cn.refund_method',
                        'cn.reason',
                        'cr.return_no',
                    ]);
            } else {
                $creditQuery->select([
                    'cn.id',
                    'cn.credit_note_no',
                    'cn.customer_return_id',
                    'cn.sale_id',
                    'cn.customer_num',
                    'cn.credit_date',
                    'cn.total_amount',
                    'cn.refund_method',
                    'cn.reason',
                ]);
            }

            $creditNotes = $creditQuery->get();
        }

        $saleIds = $invoices->pluck('sale_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $creditsBySale = $invoiceService->statementCreditsBySaleId($saleIds);
        $saleTotals = $saleIds === []
            ? collect()
            : DB::table('sales')->whereIn('id', $saleIds)->pluck('order_total', 'id');

        $invoices = $invoices->map(function ($row) use ($invoiceService, $creditsBySale, $saleTotals) {
            $saleId = $row->sale_id ? (int) $row->sale_id : null;
            $credits = $saleId ? (float) ($creditsBySale[$saleId] ?? 0) : 0.0;
            $netSale = $saleId !== null
                ? (float) ($saleTotals[$saleId] ?? $row->invoice_total)
                : (float) $row->invoice_total;
            $row->return_credit_total = round($credits, 2);
            $row->statement_debit = $invoiceService->statementDebitForInvoice(
                (float) $row->invoice_total,
                $netSale,
                $credits,
            );

            return $row;
        });

        $sales = DB::table('sales')
            ->where('customer_num', $customerNum)
            ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get();

        $totalInvoiced = $invoices->sum(fn ($row) => (float) $row->statement_debit);
        $totalCredits = $creditNotes->sum(fn ($row) => (float) $row->total_amount);
        $totalPaid = $payments->sum(fn ($row) => (float) $row->amount_paid);

        return [
            'customer' => [
                'customer_num' => $customer->customer_num,
                'customer_name' => $customer->customer_name,
                'customer_type' => $customer->customer_type,
                'phone_number' => $customer->phone_number,
                'additional_phone' => $customer->additional_phone,
                'town' => $customer->town,
                'kra_pin' => $customer->kra_pin,
                'terms_of_payment' => $customer->terms_of_payment,
                'credit_limit' => (float) $customer->credit_limit,
                'current_balance' => (float) $customer->current_balance,
                'branch_id' => $customer->branch_id,
                'branch_name' => $branchName,
                'route_id' => $customer->route_id,
                'route_name' => $routeName,
            ],
            'invoices' => $invoices->values(),
            'credit_notes' => $creditNotes->values(),
            'payments' => $payments,
            'sales' => $sales,
            'summary' => [
                'total_invoiced' => round($totalInvoiced, 2),
                'total_credits' => round($totalCredits, 2),
                'total_paid' => round($totalPaid, 2),
                'outstanding_balance' => (float) $customer->current_balance,
                'credit_limit' => (float) $customer->credit_limit,
            ],
        ];
    }

    protected function buildPriceList(Request $request, array $filters, int $page, int $perPage)
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);

        $query = DB::table('products as p')
            ->join('uoms as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('retail_package_settings as r', 'p.product_code', '=', 'r.product_code')
            ->whereNull('p.deleted_at')
            ->select([
                'p.product_code',
                'p.product_name',
                'p.unit_price',
                'p.last_cost_price',
                'p.sell_on_retail',
                'u.uom_type',
                'u.conversion_factor',
                'u.measure_name',
                'r.max_qty_measure',
                'r.markup_price',
                'r.wholesale_markup_price',
                'r.min_uom_measure',
                'r.pricing_tiers',
            ])
            ->orderBy('p.product_name');

        if ($orgId) {
            $query->where('p.organization_id', $orgId);
        }

        if (! empty($filters['branch_id'])) {
            $query->where(function ($branchQuery) use ($filters) {
                $branchQuery->where('p.branch_id', $filters['branch_id'])
                    ->orWhereNull('p.branch_id');
            });
        }

        if ($subcategoryId = ProductCatalogFilterService::resolveSubcategoryFilterId($request)) {
            $query->where('p.subcategory_id', $subcategoryId);
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $paginator->getCollection()->transform(function ($row) {
            $sell = (float) ($row->unit_price ?? 0);
            $cost = (float) ($row->last_cost_price ?? 0);
            $row->profit_margin_percent = $sell > 0 && $cost > 0
                ? round((($sell - $cost) / $sell) * 100)
                : null;

            return $row;
        });

        return $paginator;
    }

    /** @return list<int> */
    protected function organizationBranchIds(?int $organizationId): array
    {
        if (! $organizationId) {
            return [];
        }

        return DB::table('branches')
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    protected function organizationHasMultipleBranches(Request $request): bool
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return false;
        }

        return count($this->organizationBranchIds($orgId)) > 1;
    }

    protected function scopeReportQueryToOrganization($query, Request $request, string $view, array $allowedCols): void
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return;
        }

        if ($this->viewColumnExists($view, 'organization_id')) {
            $query->where('organization_id', $orgId);

            return;
        }

        if ($this->viewColumnExists($view, 'branch_id')) {
            $this->scopeOrganizationBranches($query, $orgId);

            return;
        }

        if ($view === 'v_sales_by_customer') {
            $query->whereIn('customer_num', function ($sub) use ($orgId) {
                $sub->select('customer_num')
                    ->from('customers')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
            });

            return;
        }

        if ($view === 'v_open_lpo_lines' || $view === 'v_purchases_by_supplier') {
            $query->whereIn('supplier_id', function ($sub) use ($orgId) {
                $sub->select('id')
                    ->from('suppliers')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
            });

            return;
        }

        if ($view === 'v_route_loading_summary') {
            $query->whereIn('route_name', function ($sub) use ($orgId) {
                $sub->select('route_name')
                    ->from('routes')
                    ->where('organization_id', $orgId);
            });

            return;
        }

        if (in_array('product_code', $allowedCols, true)) {
            $query->whereIn('product_code', function ($sub) use ($orgId) {
                $sub->select('product_code')
                    ->from('products')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
            });
        }
    }

    protected function scopeOrganizationBranches($query, int $organizationId, string $branchColumn = 'branch_id'): void
    {
        $query->whereIn($branchColumn, function ($sub) use ($organizationId) {
            $sub->select('id')
                ->from('branches')
                ->where('organization_id', $organizationId);
        });
    }

    protected function applySalesTenantScope($query, ?int $orgId, ?int $branchId, string $alias = ''): void
    {
        $prefix = $alias !== '' ? "{$alias}." : '';
        if ($orgId && Schema::hasColumn('sales', 'organization_id')) {
            $query->where("{$prefix}organization_id", $orgId);
        }
        $this->applyBranchTenantScope($query, $orgId, $branchId, "{$prefix}branch_id");
    }

    protected function applyBranchTenantScope($query, ?int $orgId, ?int $branchId, string $branchColumn = 'branch_id'): void
    {
        if ($branchId) {
            $query->where($branchColumn, $branchId);

            return;
        }

        if ($orgId) {
            $this->scopeOrganizationBranches($query, $orgId, $branchColumn);
        }
    }

    protected function viewColumnExists(string $view, string $column): bool
    {
        static $cache = [];

        $key = "{$view}.{$column}";
        if (! array_key_exists($key, $cache)) {
            $cache[$key] = collect(DB::select(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
                 LIMIT 1',
                [$view, $column],
            ))->isNotEmpty();
        }

        return $cache[$key];
    }

    protected function resolveReportBranchId(Request $request, ?int $requestedBranchId = null): ?int
    {
        $access = app(UserAccessService::class);
        $limitedBranch = $access->branchId($request->user());
        if ($limitedBranch !== null) {
            if ($requestedBranchId !== null && (int) $requestedBranchId !== $limitedBranch) {
                abort(403, 'You can only view reports for your assigned branch.');
            }

            return $limitedBranch;
        }

        return $requestedBranchId !== null ? (int) $requestedBranchId : null;
    }

    /**
     * Aggregate till session cash movements for EOD expected cash.
     *
     * @param  array<int, object|array>  $sessions
     * @return array{in: float, out: float}
     */
    protected function sumSessionCashMovements(array $sessions): array
    {
        $in = 0.0;
        $out = 0.0;

        foreach ($sessions as $session) {
            $raw = is_array($session)
                ? ($session['cash_movements'] ?? null)
                : ($session->cash_movements ?? null);
            $movements = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($movements)) {
                continue;
            }

            foreach ($movements as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $amount = (float) ($row['amount'] ?? 0);
                if ($amount <= 0) {
                    continue;
                }
                if (($row['type'] ?? '') === 'pay_in') {
                    $in += $amount;
                } else {
                    // drop, pay_out, and any other withdrawal from the till
                    $out += $amount;
                }
            }
        }

        return ['in' => $in, 'out' => $out];
    }
}
