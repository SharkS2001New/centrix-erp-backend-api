<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
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
                ['key' => 'profit-loss', 'path' => '/reports/profit-loss', 'label' => 'Profit & loss'],
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

        return compact('invoices', 'payments', 'sales');
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
