<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\SubledgerReconciliationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingReportController extends Controller
{
    public function __construct(
        protected AccountingReportService $reports,
        protected SubledgerReconciliationService $subledgers,
    ) {}

    public function generalLedger(Request $request)
    {
        return response()->json(
            $this->reports->generalLedger((int) $request->user()->organization_id, $this->filters($request)),
        );
    }

    public function trialBalance(Request $request)
    {
        $result = $this->reports->trialBalance((int) $request->user()->organization_id, $this->filters($request));

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
        ]);
    }

    public function balanceSheet(Request $request)
    {
        $result = $this->reports->balanceSheet((int) $request->user()->organization_id, $this->filters($request));

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
        ]);
    }

    public function profitLossGl(Request $request)
    {
        $result = $this->reports->profitAndLoss((int) $request->user()->organization_id, $this->filters($request));

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
        ]);
    }

    public function cashFlow(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $filters = $this->filters($request);

        if ($request->input('method') === 'gaap') {
            $result = $this->reports->gaapCashFlow($orgId, $filters);

            return response()->json([
                'method' => $result['method'],
                'sections' => $result['sections'],
                'data' => $result['data'],
                'summary' => $result['summary'],
            ]);
        }

        $result = $this->reports->cashFlow($orgId, $filters);

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
        ]);
    }

    public function accountsReceivable(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');
        $hasDateFilter = $request->filled('from_date') || $request->filled('to_date');

        if (! $hasDateFilter) {
            $q = DB::table('v_accounts_receivable_summary')
                ->where('organization_id', $orgId);
            foreach (['customer_num'] as $col) {
                if ($request->filled($col)) {
                    $q->where($col, $request->input($col));
                }
            }

            return response()->json($q->orderByDesc('total_outstanding')->paginate(
                min((int) $request->input('per_page', 50), 200),
            ));
        }

        $invoiceSub = DB::table('customer_invoices as ci')
            ->leftJoin('sales as s', 's.id', '=', 'ci.sale_id')
            ->where('ci.organization_id', $orgId)
            ->whereIn('ci.payment_status', [0, 1])
            ->whereNull('ci.deleted_at')
            ->where(function ($query) {
                $query->whereNull('s.id')
                    ->orWhereNotIn('s.status', ['cancelled', 'expired']);
            })
            ->when($fromDate, fn ($query) => $query->where('ci.invoice_date', '>=', $fromDate))
            ->when($toDate, fn ($query) => $query->where('ci.invoice_date', '<=', $toDate))
            ->groupBy('ci.organization_id', 'ci.customer_num')
            ->select([
                'ci.organization_id',
                'ci.customer_num',
                DB::raw('SUM(ci.balance_due) AS open_invoice_total'),
                DB::raw('COUNT(*) AS open_invoice_count'),
            ]);

        $creditSub = DB::table('sales as s')
            ->where('s.organization_id', $orgId)
            ->where('s.status', 'completed')
            ->where('s.is_credit_sale', 1)
            ->whereIn('s.payment_status', ['unpaid', 'partial'])
            ->whereNotNull('s.customer_num')
            ->when($fromDate, fn ($query) => $query->whereDate('s.completed_at', '>=', $fromDate))
            ->when($toDate, fn ($query) => $query->whereDate('s.completed_at', '<=', $toDate))
            ->groupBy('s.organization_id', 's.customer_num')
            ->select([
                's.organization_id',
                's.customer_num',
                DB::raw('SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding'),
            ]);

        $q = DB::table('customers as c')
            ->leftJoin('routes as r', 'c.route_id', '=', 'r.id')
            ->leftJoinSub($invoiceSub, 'inv', function ($join) {
                $join->on('inv.customer_num', '=', 'c.customer_num')
                    ->on('inv.organization_id', '=', 'c.organization_id');
            })
            ->leftJoinSub($creditSub, 'credit', function ($join) {
                $join->on('credit.customer_num', '=', 'c.customer_num')
                    ->on('credit.organization_id', '=', 'c.organization_id');
            })
            ->where('c.organization_id', $orgId)
            ->whereNull('c.deleted_at')
            ->when($request->filled('customer_num'), fn ($query) => $query->where('c.customer_num', $request->input('customer_num')))
            ->select([
                'c.organization_id',
                'c.customer_num',
                'c.customer_name',
                'c.phone_number',
                'r.route_name',
                DB::raw('0 AS customer_balance'),
                DB::raw('COALESCE(inv.open_invoice_total, 0) AS invoice_balance_due'),
                DB::raw('COALESCE(inv.open_invoice_count, 0) AS open_invoices'),
                DB::raw('COALESCE(credit.credit_sales_outstanding, 0) AS credit_sales_outstanding'),
                DB::raw(
                    'COALESCE(inv.open_invoice_total, 0) + COALESCE(credit.credit_sales_outstanding, 0) AS total_outstanding',
                ),
            ])
            // Use the SELECT alias so Laravel's paginate() count subquery works in MySQL.
            ->havingRaw('total_outstanding > 0');

        return response()->json($q->orderByDesc('total_outstanding')->paginate(
            min((int) $request->input('per_page', 50), 200),
        ));
    }

    public function accountsPayable(Request $request)
    {
        $q = DB::table('v_supplier_payables');
        foreach (['supplier_id'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }

        return response()->json($q->orderByDesc('balance_due')->paginate(
            min((int) $request->input('per_page', 50), 200),
        ));
    }

    public function subledgerReconciliation(Request $request)
    {
        return response()->json(
            $this->subledgers->summarize(
                (int) $request->user()->organization_id,
                $this->filters($request),
            ),
        );
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return $request->only([
            'branch_id', 'account_id', 'from_date', 'to_date', 'per_page', 'page', 'method', 'q',
        ]);
    }
}
