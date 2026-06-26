<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\Auth\UserAccessService;
use App\Services\Legacy\LegacyArchiveReader;
use App\Services\Legacy\OrganizationLegacyArchiveService;
use App\Services\Erp\ErpContext;
use App\Services\Sales\CentrixSalesScope;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct(protected ErpContext $erp) {}

    /** Report catalog for ERP clients (bootstrap UI). */
    public function catalog()
    {
        return response()->json([
            'sales' => [
                ['key' => 'sales-by-product', 'path' => '/reports/sales-by-product', 'label' => 'Sales by product'],
                ['key' => 'sales-by-user', 'path' => '/reports/sales-by-user', 'label' => 'Sales by cashier / user'],
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
            'inventory' => [
                ['key' => 'stock-on-hand', 'path' => '/reports/stock-on-hand', 'label' => 'Stock on hand'],
                ['key' => 'low-stock', 'path' => '/reports/low-stock', 'label' => 'Low stock / reorder'],
                ['key' => 'stock-movement', 'path' => '/reports/stock-movement', 'label' => 'Stock ledger (transactions)'],
                ['key' => 'stock-chain', 'path' => '/reports/stock-chain', 'label' => 'Stock chain (receive → sell)'],
                ['key' => 'stock-valuation', 'path' => '/reports/stock-valuation', 'label' => 'Stock valuation'],
                ['key' => 'stock-reservations', 'path' => '/reports/stock-reservations', 'label' => 'Active cart reservations'],
                ['key' => 'stock-receipts', 'path' => '/reports/stock-receipts', 'label' => 'Purchase receipts'],
                ['key' => 'stock-transfers', 'path' => '/reports/stock-transfers', 'label' => 'Shop ↔ store transfers'],
                ['key' => 'open-lpo', 'path' => '/reports/open-lpo', 'label' => 'Open LPO lines (pending receive)'],
                ['key' => 'purchases-by-supplier', 'path' => '/reports/purchases-by-supplier', 'label' => 'Purchases by supplier'],
                ['key' => 'damages', 'path' => '/reports/damages', 'label' => 'Damages & write-offs'],
                ['key' => 'supplier-returns', 'path' => '/reports/supplier-returns', 'label' => 'Supplier returns'],
                ['key' => 'returns', 'path' => '/reports/returns', 'label' => 'Customer returns'],
                ['key' => 'price-list', 'path' => '/reports/price-list', 'label' => 'Price list'],
            ],
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
            'include_legacy_archive' => 'nullable|boolean',
        ]);

        $period = AppTimezone::reportPeriod(
            $data['from_date'] ?? null,
            $data['to_date'] ?? null,
        );
        $from = $period['from'];
        $to = $period['to'];
        $prevFrom = $period['prev_from'];
        $prevTo = $period['prev_to'];
        $branchId = $data['branch_id'] ?? null;
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);

        $salesBase = function (\Carbon\Carbon $start, \Carbon\Carbon $end) use ($branchId, $orgId) {
            $query = DB::table('sales')
                ->where('status', 'completed')
                ->where('archived', 0)
                ->whereDate('completed_at', '>=', $start->toDateString())
                ->whereDate('completed_at', '<=', $end->toDateString())
                ->when($orgId, fn ($q) => $q->where('organization_id', $orgId))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

            return CentrixSalesScope::excludeLegacyMaterialized($query);
        };

        $totalSales = (float) $salesBase($from, $to)->sum('order_total');
        $prevTotalSales = (float) $salesBase($prevFrom, $prevTo)->sum('order_total');

        $plBase = fn (\Carbon\Carbon $start, \Carbon\Carbon $end) => DB::table('v_profit_loss_summary')
            ->where('period', '>=', $start->toDateString())
            ->where('period', '<=', $end->toDateString())
            ->when($orgId, fn ($q) => $q->whereIn('branch_id', $this->organizationBranchIds($orgId)))
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
            ->when($branchId, function ($q) use ($branchId) {
                $q->join('customer_invoices as ci', 'ci.id', '=', 'p.customer_invoice_id')
                    ->where('ci.branch_id', $branchId);
            })
            ->whereDate('p.date_paid', '>=', $from->toDateString())
            ->whereDate('p.date_paid', '<=', $to->toDateString())
            ->sum('p.amount_paid');

        $prevReceivables = max(0, $receivables - $creditIssued + $paymentsCollected);

        $inventoryValue = (float) DB::table('v_stock_valuation')
            ->when($orgId, fn ($q) => $q->whereIn('branch_id', $this->organizationBranchIds($orgId)))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('retail_value');

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
            ->selectRaw('DATE(completed_at) as day, SUM(order_total) as total')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $dailyPrevious = $salesBase($prevFrom, $prevTo)
            ->selectRaw('DATE(completed_at) as day, SUM(order_total) as total')
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
            ->when($orgId, fn ($q) => $q->whereIn('branch_id', $this->organizationBranchIds($orgId)))
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

        $channelTotal = (float) $channelRows->sum('revenue');
        $salesByChannel = $channelRows->map(fn ($row) => [
            'channel' => $row->channel ?: 'other',
            'revenue' => (float) $row->revenue,
            'orders' => (int) $row->orders,
            'share_pct' => $channelTotal > 0 ? round(((float) $row->revenue / $channelTotal) * 100, 1) : 0,
        ])->values()->all();

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
                    'change_pct' => $this->pctChange($inventoryValue, $prevInventory),
                ],
            ],
            'sales_trend' => $salesTrend,
            'top_products' => $topProducts,
            'sales_by_channel' => $salesByChannel,
        ];

        if ($request->boolean('include_legacy_archive')) {
            $org = $this->erp->resolveOrganization($request);
            $archive = app(LegacyArchiveReader::class);
            if ($archive->isAvailable($org) && $archive->shouldMergeForRange($org, $from, $to)) {
                $merged = $archive->mergeSummaryForReports($org, [
                    'order_total' => $totalSales,
                ], $from, $to);

                if ($merged) {
                    $payload['legacy_archive'] = [
                        'label' => app(\App\Services\Legacy\OrganizationLegacyArchiveService::class)->forOrganization($org)['label'] ?? 'LightStores archive',
                        'cutover_date' => $archive->cutoverDate($org)?->toDateString(),
                        'summary' => $merged['archive'],
                        'kpis' => [
                            'total_sales' => [
                                'live' => $totalSales,
                                'archive' => $merged['archive']['order_total'],
                                'combined' => $merged['combined']['order_total'],
                            ],
                        ],
                    ];
                }
            }
        }

        return response()->json($payload);
    }

    protected function pctChange(float $current, float $previous): ?float
    {
        if ($previous == 0.0) {
            return $current > 0 ? 100.0 : 0.0;
        }

        return round((($current - $previous) / abs($previous)) * 100, 1);
    }

    public function salesByProduct(Request $request)
    {
        return response()->json($this->reportFromView('v_sales_by_product', $this->filters($request), [
            'sale_date', 'branch_id', 'product_code', 'channel',
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
        return response()->json($this->reportFromView('v_sales_by_customer', $this->filters($request), [
            'customer_num', 'route_name',
        ]));
    }

    public function salesByChannel(Request $request)
    {
        $paginator = $this->reportFromView('v_sales_by_channel', $this->filters($request), [
            'sale_date', 'branch_id', 'channel', 'payment_status',
        ]);

        if (! $request->boolean('include_legacy_archive')) {
            return response()->json($paginator);
        }

        return $this->withLegacyArchiveRows(
            $request,
            $paginator,
            fn ($org, $from, $to, $page, $perPage) => app(LegacyArchiveReader::class)->paginatedSalesByChannelRows($org, $from, $to, $page, $perPage),
        );
    }

    public function dailySales(Request $request)
    {
        $paginator = $this->reportFromView('v_daily_sales', $this->filters($request), [
            'sale_day', 'branch_id', 'channel',
        ]);

        if (! $request->boolean('include_legacy_archive')) {
            return response()->json($paginator);
        }

        return $this->withLegacyArchiveRows(
            $request,
            $paginator,
            fn ($org, $from, $to, $page, $perPage) => app(LegacyArchiveReader::class)->paginatedDailySalesRows($org, $from, $to, $page, $perPage),
        );
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
            'scheduled_date', 'route_name', 'driver_name', 'status',
        ]));
    }

    public function tripCashSettlement(Request $request)
    {
        return response()->json($this->reportFromView('v_trip_cash_settlement', $this->filters($request), [
            'scheduled_date', 'route_name', 'driver_name', 'status',
        ]));
    }

    public function podCompliance(Request $request)
    {
        return response()->json($this->reportFromView('v_pod_compliance', $this->filters($request), [
            'capture_date', 'route_name', 'driver_name',
        ]));
    }

    public function driverDeliveries(Request $request)
    {
        return response()->json($this->reportFromView('v_driver_deliveries', $this->filters($request), [
            'delivery_date', 'driver_name', 'route_name',
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
            'sale_date', 'branch_id', 'category_id', 'sub_category_id',
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
            $q->whereIn('branch_id', $this->organizationBranchIds($orgId));
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
            $q->with(['product:product_code,product_name,unit_id'])
                ->orderByDesc('id')
                ->paginate(min((int) $request->input('per_page', 50), 200)),
        );
    }

    public function stockChain(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_chain', $this->filters($request), [
            'branch_id', 'product_code',
        ]));
    }

    public function stockValuation(Request $request)
    {
        return response()->json($this->paginatedStockReport(
            $request,
            'v_stock_valuation',
            ['branch_id', 'product_code'],
        ));
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

    public function openLpo(Request $request)
    {
        return response()->json($this->reportFromView('v_open_lpo_lines', $this->filters($request), [
            'lpo_no', 'supplier_id', 'product_code', 'lpo_status_code',
        ]));
    }

    public function profitLoss(Request $request)
    {
        return response()->json($this->reportFromView('v_profit_loss_summary', $this->filters($request), [
            'period', 'branch_id',
        ]));
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
        if ($isMonthly) {
            $salesBase->whereBetween('completed_at', [$periodStart, $periodEnd]);
        } else {
            $salesBase->whereDate('completed_at', $date);
        }
        if ($branchId) {
            $salesBase->where('branch_id', $branchId);
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
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId)),
            's',
        );
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
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId)),
            's',
        );
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
            ->where('status', 'cancelled')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId));
        if ($isMonthly) {
            $voidedQuery->whereBetween('cancelled_at', [$periodStart, $periodEnd]);
        } else {
            $voidedQuery->whereDate('cancelled_at', $date);
        }
        $voided = $voidedQuery->count();

        $gross = (float) ($agg->gross_sales ?? 0);
        $totalVat = round((float) ($agg->total_vat ?? 0), 2);
        $totalDiscounts = (float) ($agg->order_discounts ?? 0) + $lineDiscounts;
        $netSales = max(0, $gross - $totalDiscounts - $refunds);

        $cash = (float) ($agg->cash_collected ?? 0);
        $mpesa = (float) ($agg->mpesa_collected ?? 0);
        $bank = (float) ($agg->equity_collected ?? 0) + (float) ($agg->kcb_collected ?? 0);

        $sessionQ = DB::table('till_float_sessions as tfs');
        if ($isMonthly) {
            $sessionQ->whereBetween('tfs.session_date', [$periodStartDate, $periodEndDate]);
        } else {
            $sessionQ->whereDate('tfs.session_date', $date);
        }
        if ($branchId) {
            $sessionQ->where('tfs.branch_id', $branchId);
        }
        if ($cashierId) {
            $sessionQ->where('tfs.cashier_id', $cashierId);
        }
        $openingFloat = (float) (clone $sessionQ)->sum('working_amount');

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
            ->when($branchId, fn ($q) => $q->where('tfs.branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('tfs.cashier_id', $cashierId));
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
                ->where('s.archived', 0)
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId)),
            's',
        );
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
                DB::raw('COALESCE(SUM(s.cash), 0) as cash_collected'),
                DB::raw('COALESCE(SUM(s.mpesa_amount), 0) as mpesa_collected'),
                DB::raw('COALESCE(SUM(s.equity_amount), 0) + COALESCE(SUM(s.kcb_amount), 0) as bank_collected'),
            )
            ->get()
            ->map(function ($row) use ($date, $branchId, $isMonthly, $periodStartDate, $periodEndDate) {
                $floatQuery = DB::table('till_float_sessions')
                    ->where('cashier_id', $row->cashier_id);
                if ($isMonthly) {
                    $floatQuery->whereBetween('session_date', [$periodStartDate, $periodEndDate]);
                } else {
                    $floatQuery->whereDate('session_date', $date);
                }
                if ($branchId) {
                    $floatQuery->where('branch_id', $branchId);
                }
                $row->opening_float = (float) $floatQuery->sum('working_amount');

                return $row;
            });

        $expenseRowsQuery = DB::table('v_expenses_summary')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
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
            ->when($branchId, fn ($q) => $q->where('tfs.branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('tfs.cashier_id', $cashierId));
        if ($isMonthly) {
            $sessionExpenseQ->whereBetween('tfs.session_date', [$periodStartDate, $periodEndDate]);
        } else {
            $sessionExpenseQ->whereDate('tfs.session_date', $date);
        }
        $sessionExpenses = (float) $sessionExpenseQ->sum('e.expense_amount');

        $creditPaymentsQuery = DB::table('customer_invoice_payments');
        if ($isMonthly) {
            $creditPaymentsQuery->whereBetween('date_paid', [$periodStartDate, $periodEndDate]);
        } else {
            $creditPaymentsQuery->whereDate('date_paid', $date);
        }
        $creditPayments = (float) $creditPaymentsQuery->sum('amount_paid');

        $closingDebtors = (float) DB::table('customers')
            ->whereNull('deleted_at')
            ->when($branchId, function ($q) use ($branchId) {
                $q->whereIn('customer_num', function ($sub) use ($branchId) {
                    $sub->select('customer_num')
                        ->from('sales')
                        ->where('branch_id', $branchId)
                        ->whereNotNull('customer_num');
                });
            })
            ->sum('current_balance');

        $creditSales = (float) ($agg->credit_sales ?? 0);
        $netCashExpected = $openingFloat + $cash;
        $netSalesMinusFloat = $openingFloat > 0 ? max(0, round($netSales - $openingFloat, 2)) : null;
        $netPosition = $netCashExpected - $totalExpenses - $closingDebtors;

        $dailyBreakdown = null;
        if ($isMonthly) {
            $dailyBreakdown = CentrixSalesScope::excludeLegacyMaterialized(
                DB::table('sales')
                    ->where('status', 'completed')
                    ->where('archived', 0)
                    ->whereBetween('completed_at', [$periodStart, $periodEnd])
                    ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                    ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId)),
            )
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
                'transactions' => (int) ($agg->transactions ?? 0),
                'total_discounts' => $totalDiscounts,
                'total_refunds' => $refunds,
                'net_sales' => $netSales,
                'total_vat' => $totalVat,
                'opening_float' => $openingFloat,
                'net_sales_minus_float' => $netSalesMinusFloat,
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
        return response()->json($this->reportFromView('v_top_debtors', $this->filters($request), [
            'customer_num', 'route_name',
        ]));
    }

    public function invoicePayments(Request $request)
    {
        return response()->json($this->reportFromView('v_invoice_payment_history', $this->filters($request), [
            'customer_num', 'date_paid',
        ]));
    }

    public function purchasesBySupplier(Request $request)
    {
        return response()->json($this->reportFromView('v_purchases_by_supplier', $this->filters($request), [
            'supplier_id', 'lpo_no',
        ]));
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

    public function customerStatement(int $customerNum)
    {
        return response()->json($this->buildCustomerStatement($customerNum));
    }

    public function returns(Request $request)
    {
        $q = \App\Models\ReturnRecord::query();
        foreach (['branch_id', 'product_code', 'return_type'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }
        if ($request->filled('from_date')) {
            $q->where('created_at', '>=', $request->input('from_date'));
        }
        if ($request->filled('to_date')) {
            $q->where('created_at', '<=', $request->input('to_date'));
        }

        return response()->json($q->orderByDesc('id')->paginate(min((int) $request->input('per_page', 50), 200)));
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
            'stock_location', 'from_location', 'to_location', 'lpo_status_code',
            'category_id', 'sub_category_id', 'till_id', 'reference_type', 'user_id',
            'table_name', 'action',
        ]);

        $user = $request->user();
        if ($user && empty($filters['branch_id'])) {
            $branchId = app(UserAccessService::class)->branchId($user);
            if ($branchId !== null) {
                $filters['branch_id'] = $branchId;
            }
        }

        return $filters;
    }

    protected function reportFromView(string $view, array $filters, array $allowedCols)
    {
        $request = request();
        $q = DB::table($view);
        $this->scopeReportQueryToOrganization($q, $request, $view, $allowedCols);

        foreach ($allowedCols as $col) {
            if (isset($filters[$col]) && $filters[$col] !== '') {
                $q->where($col, $filters[$col]);
            }
        }
        if (! empty($filters['from_date']) && ! empty($filters['date_column'])) {
            $q->where($filters['date_column'], '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date']) && ! empty($filters['date_column'])) {
            $q->where($filters['date_column'], '<=', $filters['to_date']);
        }

        return $q->paginate(min((int) ($filters['per_page'] ?? 20), 200));
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

        if ($request->filled('category_id')) {
            $q->whereIn('product_code', function ($sub) use ($request) {
                $sub->select('p.product_code')
                    ->from('products as p')
                    ->join('sub_categories as sc', 'sc.id', '=', 'p.subcategory_id')
                    ->where('sc.category_id', (int) $request->input('category_id'))
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

        $perPage = min((int) $request->input('per_page', 25), 200);

        return $q->orderBy('product_name')->paginate($perPage);
    }

    protected function buildCustomerStatement(int $customerNum): array
    {
        $customer = Customer::query()
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at')
            ->firstOrFail();

        $branchName = DB::table('branches')
            ->where('id', $customer->branch_id)
            ->value('branch_name');

        $routeName = $customer->route_id
            ? DB::table('routes')->where('id', $customer->route_id)->value('route_name')
            : null;

        $invoices = DB::table('customer_invoices')
            ->where('customer_num', $customerNum)
            ->whereNull('deleted_at')
            ->orderBy('invoice_date')
            ->get();

        $payments = DB::table('customer_invoice_payments')
            ->where('customer_num', $customerNum)
            ->orderBy('date_paid')
            ->get();

        $sales = DB::table('sales')
            ->where('customer_num', $customerNum)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->limit(100)
            ->get();

        $totalInvoiced = $invoices->sum(fn ($row) => (float) $row->invoice_total);
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
            'invoices' => $invoices,
            'payments' => $payments,
            'sales' => $sales,
            'summary' => [
                'total_invoiced' => round($totalInvoiced, 2),
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

        return $query->paginate($perPage, ['*'], 'page', $page);
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

    protected function scopeReportQueryToOrganization($query, Request $request, string $view, array $allowedCols): void
    {
        $orgId = app(UserAccessService::class)->organizationId($request->user(), $request);
        if (! $orgId) {
            return;
        }

        if (in_array('branch_id', $allowedCols, true)) {
            $query->whereIn('branch_id', $this->organizationBranchIds($orgId));

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

        if ($view === 'v_open_lpo_lines') {
            $query->whereIn('supplier_id', function ($sub) use ($orgId) {
                $sub->select('id')
                    ->from('suppliers')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
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
}
