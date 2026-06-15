<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
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
                ['key' => 'mobile-route-sales', 'path' => '/reports/mobile-route-sales', 'label' => 'Mobile / route sales'],
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
                ['key' => 'payroll-summary', 'path' => '/reports/payroll-summary', 'label' => 'Payroll runs'],
                ['key' => 'audit-trail', 'path' => '/reports/audit-trail', 'label' => 'Audit trail'],
            ],
            'customer' => [
                ['key' => 'customer-statement', 'path' => '/reports/customers/{customerNum}/statement', 'label' => 'Customer statement'],
            ],
            'filters' => [
                'branch_id', 'product_code', 'channel', 'cashier_id', 'customer_num',
                'supplier_id', 'route_name', 'sale_date', 'sale_day', 'period',
                'from_date', 'to_date', 'date_column', 'per_page', 'aging_bucket',
                'status', 'payment_status', 'lpo_no', 'organization_id', 'expense_group_id',
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

        $to = isset($data['to_date'])
            ? \Carbon\Carbon::parse($data['to_date'])->startOfDay()
            : now()->startOfDay();
        $from = isset($data['from_date'])
            ? \Carbon\Carbon::parse($data['from_date'])->startOfDay()
            : $to->copy()->subDays(29);
        if ($from->gt($to)) {
            [$from, $to] = [$to->copy(), $from->copy()];
        }

        $days = $from->diffInDays($to) + 1;
        $prevTo = $from->copy()->subDay();
        $prevFrom = $prevTo->copy()->subDays($days - 1);
        $branchId = $data['branch_id'] ?? null;

        $salesBase = fn (\Carbon\Carbon $start, \Carbon\Carbon $end) => DB::table('sales')
            ->where('status', 'completed')
            ->where('archived', 0)
            ->whereDate('completed_at', '>=', $start->toDateString())
            ->whereDate('completed_at', '<=', $end->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $totalSales = (float) $salesBase($from, $to)->sum('order_total');
        $prevTotalSales = (float) $salesBase($prevFrom, $prevTo)->sum('order_total');

        $plBase = fn (\Carbon\Carbon $start, \Carbon\Carbon $end) => DB::table('v_profit_loss_summary')
            ->where('period', '>=', $start->toDateString())
            ->where('period', '<=', $end->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $grossProfit = (float) $plBase($from, $to)->sum('gross_profit');
        $prevGrossProfit = (float) $plBase($prevFrom, $prevTo)->sum('gross_profit');

        $receivables = (float) DB::table('customer_invoices')
            ->whereNull('deleted_at')
            ->where('balance_due', '>', 0)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('balance_due');

        $creditIssued = (float) DB::table('sales')
            ->where('status', 'completed')
            ->where('archived', 0)
            ->where('is_credit_sale', 1)
            ->whereDate('completed_at', '>=', $from->toDateString())
            ->whereDate('completed_at', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('order_total');

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
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('retail_value');

        $receiptValue = (float) DB::table('stock_receipts')
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('SUM(units_received * COALESCE(cost_price, 0)) as total')
            ->value('total');

        $prevReceiptValue = (float) DB::table('stock_receipts')
            ->whereDate('created_at', '>=', $prevFrom->toDateString())
            ->whereDate('created_at', '<=', $prevTo->toDateString())
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

        return response()->json([
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
        ]);
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
            'loading_date', 'route_name',
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
        return response()->json($this->reportFromView('v_stock_on_hand', $this->filters($request), [
            'branch_id', 'product_code',
        ]));
    }

    public function lowStock(Request $request)
    {
        return response()->json($this->reportFromView('v_low_stock', $this->filters($request), [
            'branch_id', 'product_code',
        ]));
    }

    public function stockMovement(Request $request)
    {
        $q = \App\Models\InventoryTransaction::query();
        foreach (['branch_id', 'product_code', 'transaction_type', 'stock_location'] as $col) {
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

    public function stockChain(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_chain', $this->filters($request), [
            'branch_id', 'product_code',
        ]));
    }

    public function stockValuation(Request $request)
    {
        return response()->json($this->reportFromView('v_stock_valuation', $this->filters($request), [
            'branch_id', 'product_code',
        ]));
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

    /** Full end-of-day dashboard payload for a branch and date. */
    public function eodReport(Request $request)
    {
        $data = $request->validate([
            'sale_date' => 'required|date',
            'branch_id' => 'nullable|integer',
            'cashier_id' => 'nullable|integer',
        ]);

        $date = $data['sale_date'];
        $branchId = $data['branch_id'] ?? null;
        $cashierId = isset($data['cashier_id']) ? (int) $data['cashier_id'] : null;
        if ($cashierId <= 0) {
            $cashierId = null;
        }

        $salesBase = DB::table('sales')
            ->where('status', 'completed')
            ->where('archived', 0)
            ->whereDate('completed_at', $date);
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
            COALESCE(SUM(order_discount), 0) as order_discounts,
            COALESCE(SUM(cash), 0) as cash_collected,
            COALESCE(SUM(mpesa_amount), 0) as mpesa_collected,
            COALESCE(SUM(equity_amount), 0) as equity_collected,
            COALESCE(SUM(kcb_amount), 0) as kcb_collected,
            COALESCE(SUM(CASE WHEN is_credit_sale = 1 THEN order_total ELSE 0 END), 0) as credit_sales,
            MIN(completed_at) as first_sale_at,
            MAX(completed_at) as last_sale_at
        ')->first();

        $lineDiscounts = (float) DB::table('sale_items as si')
            ->join('sales as s', 'si.sale_id', '=', 's.id')
            ->where('s.status', 'completed')
            ->where('s.archived', 0)
            ->whereDate('s.completed_at', $date)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId))
            ->sum('si.discount_given');

        $itemsSold = (float) DB::table('sale_items as si')
            ->join('sales as s', 'si.sale_id', '=', 's.id')
            ->where('s.status', 'completed')
            ->where('s.archived', 0)
            ->whereDate('s.completed_at', $date)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('s.cashier_id', $cashierId))
            ->sum('si.quantity');

        $saleIds = (clone $salesBase)->pluck('id');
        $refunds = $saleIds->isEmpty()
            ? 0
            : (float) DB::table('returns')->whereIn('sale_id', $saleIds)->sum('amount');

        $voided = DB::table('sales')
            ->where('status', 'cancelled')
            ->whereDate('cancelled_at', $date)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('cashier_id', $cashierId))
            ->count();

        $gross = (float) ($agg->gross_sales ?? 0);
        $totalDiscounts = (float) ($agg->order_discounts ?? 0) + $lineDiscounts;
        $netSales = max(0, $gross - $totalDiscounts - $refunds);

        $cash = (float) ($agg->cash_collected ?? 0);
        $mpesa = (float) ($agg->mpesa_collected ?? 0);
        $bank = (float) ($agg->equity_collected ?? 0) + (float) ($agg->kcb_collected ?? 0);

        $sessionQ = DB::table('till_float_sessions as tfs')
            ->whereDate('tfs.session_date', $date);
        if ($branchId) {
            $sessionQ->where('tfs.branch_id', $branchId);
        }
        if ($cashierId) {
            $sessionQ->where('tfs.cashier_id', $cashierId);
        }
        $openingFloat = (float) (clone $sessionQ)->sum('working_amount');

        $tillRows = DB::table('till_float_sessions as tfs')
            ->join('tills as t', 'tfs.till_id', '=', 't.id')
            ->join('users as u', 'tfs.cashier_id', '=', 'u.id')
            ->leftJoin(DB::raw('(
                SELECT float_session_id, COUNT(*) AS txn_count, SUM(order_total) AS gross
                FROM sales WHERE status = \'completed\' GROUP BY float_session_id
            ) s'), 's.float_session_id', '=', 'tfs.id')
            ->whereDate('tfs.session_date', $date)
            ->when($branchId, fn ($q) => $q->where('tfs.branch_id', $branchId))
            ->when($cashierId, fn ($q) => $q->where('tfs.cashier_id', $cashierId))
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

        $cashierRows = DB::table('sales as s')
            ->join('users as u', 's.cashier_id', '=', 'u.id')
            ->where('s.status', 'completed')
            ->where('s.archived', 0)
            ->whereDate('s.completed_at', $date)
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
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
            ->map(function ($row) use ($date, $branchId) {
                $floatQuery = DB::table('till_float_sessions')
                    ->where('cashier_id', $row->cashier_id)
                    ->whereDate('session_date', $date);
                if ($branchId) {
                    $floatQuery->where('branch_id', $branchId);
                }
                $row->opening_float = (float) $floatQuery->sum('working_amount');

                return $row;
            });

        $expenseRows = DB::table('v_expenses_summary')
            ->where('expense_date', $date)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->select('group_name', DB::raw('SUM(total_amount) as amount'))
            ->groupBy('group_name')
            ->get();

        $totalExpenses = $expenseRows->sum(fn ($r) => (float) $r->amount);

        $creditPayments = (float) DB::table('customer_invoice_payments')
            ->whereDate('date_paid', $date)
            ->sum('amount_paid');

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
        $netPosition = $netCashExpected - $totalExpenses - $closingDebtors;

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
            'sale_date' => $date,
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
                'opening_float' => $openingFloat,
                'net_cash_expected' => $netCashExpected,
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
            'debtors' => [
                'opening' => null,
                'new_credit_sales' => $creditSales,
                'payments_received' => $creditPayments,
                'closing' => $closingDebtors,
            ],
            'net_position' => $netPosition,
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
        $q = DB::table('audit_logs');
        foreach (['user_id', 'branch_id', 'table_name', 'action'] as $col) {
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

    public function priceList(Request $request)
    {
        return response()->json($this->buildPriceList($request->input('branch_id')));
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
        return $request->only([
            'branch_id', 'product_code', 'channel', 'cashier_id', 'customer_num',
            'supplier_id', 'route_name', 'sale_date', 'sale_day', 'period',
            'from_date', 'to_date', 'date_column', 'per_page', 'aging_bucket',
            'status', 'payment_status', 'lpo_no', 'organization_id', 'expense_group_id',
            'order_date', 'loading_date', 'receipt_date', 'return_date', 'damage_date',
            'transfer_date', 'payment_date', 'entry_date', 'session_date', 'method_code',
            'stock_location', 'from_location', 'to_location', 'lpo_status_code',
            'category_id', 'sub_category_id', 'till_id', 'reference_type', 'user_id',
            'table_name', 'action',
        ]);
    }

    protected function reportFromView(string $view, array $filters, array $allowedCols)
    {
        $q = DB::table($view);
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

        return $q->paginate(min((int) ($filters['per_page'] ?? 50), 200));
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

    protected function buildPriceList(?int $branchId = null)
    {
        return DB::table('products as p')
            ->join('uoms as u', 'p.unit_id', '=', 'u.id')
            ->leftJoin('retail_package_settings as r', 'p.product_code', '=', 'r.product_code')
            ->whereNull('p.deleted_at')
            ->select([
                'p.product_code', 'p.product_name', 'p.unit_price', 'u.uom_type', 'u.conversion_factor',
                'r.max_qty_measure', 'r.markup_price', 'r.wholesale_markup_price', 'r.min_uom_measure',
            ])
            ->get();
    }
}
