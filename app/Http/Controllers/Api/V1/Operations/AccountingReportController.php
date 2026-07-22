<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\CustomerInvoiceService;
use App\Services\Accounting\SubledgerReconciliationService;
use App\Services\Erp\OrderWorkflowService;
use App\Support\EffectiveSaleDate;
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

            $paginator = $q->orderByDesc('total_outstanding')->paginate(
                min((int) $request->input('per_page', 50), 200),
            );
            $totalOutstanding = (float) DB::table('v_accounts_receivable_summary')
                ->where('organization_id', $orgId)
                ->when($request->filled('customer_num'), fn ($query) => $query->where('customer_num', $request->input('customer_num')))
                ->sum('total_outstanding');

            return response()->json(array_merge($paginator->toArray(), [
                'summary' => ['total_outstanding' => round($totalOutstanding, 2)],
            ]));
        }

        $balanceDueSql = CustomerInvoiceService::balanceDueFromPaymentsSql('ci');
        $invoiceSub = DB::table('customer_invoices as ci')
            ->leftJoin('sales as s', 's.id', '=', 'ci.sale_id')
            ->where('ci.organization_id', $orgId)
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
                DB::raw("SUM({$balanceDueSql}) AS open_invoice_total"),
                DB::raw('COUNT(*) AS open_invoice_count'),
            ])
            ->havingRaw("SUM({$balanceDueSql}) > 0");

        $creditSub = DB::table('sales as s')
            ->where('s.organization_id', $orgId)
            ->whereIn('s.status', app(OrderWorkflowService::class)->metricSaleStatuses())
            ->where('s.is_credit_sale', 1)
            ->whereIn('s.payment_status', ['unpaid', 'partial'])
            ->whereNotNull('s.customer_num')
            ->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('customer_invoices as ci')
                    ->whereColumn('ci.sale_id', 's.id')
                    ->whereNull('ci.deleted_at');
            })
            ->groupBy('s.organization_id', 's.customer_num')
            ->select([
                's.organization_id',
                's.customer_num',
                DB::raw('SUM(s.order_total - s.amount_paid) AS credit_sales_outstanding'),
            ]);
        if ($fromDate || $toDate) {
            EffectiveSaleDate::applyFromToDateFilter($creditSub, $fromDate, $toDate, 's');
        }

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

        $paginator = $q->orderByDesc('total_outstanding')->paginate(
            min((int) $request->input('per_page', 50), 200),
        );
        $totalOutstanding = (float) (DB::query()
            ->fromSub((clone $q)->reorder(), 'ar_filtered')
            ->selectRaw('COALESCE(SUM(total_outstanding), 0) as total_outstanding')
            ->value('total_outstanding') ?? 0);

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => ['total_outstanding' => round($totalOutstanding, 2)],
        ]));
    }

    public function accountsPayable(Request $request)
    {
        $orgId = (int) $request->user()->organization_id;
        $q = DB::table('v_supplier_payables as sp')
            ->whereIn('sp.supplier_id', function ($sub) use ($orgId) {
                $sub->select('id')
                    ->from('suppliers')
                    ->where('organization_id', $orgId)
                    ->whereNull('deleted_at');
            });

        foreach (['supplier_id'] as $col) {
            if ($request->filled($col)) {
                $q->where("sp.{$col}", $request->input($col));
            }
        }

        if ($search = trim((string) $request->input('q', ''))) {
            $q->where(function ($inner) use ($search) {
                $inner->where('sp.supplier_name', 'like', "%{$search}%")
                    ->orWhere('sp.supplier_code', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 200);
        $summaryRaw = DB::query()
            ->fromSub(clone $q, 'payables_filtered')
            ->selectRaw('COUNT(*) as supplier_count')
            ->selectRaw('COALESCE(SUM(balance_due), 0) as balance_due')
            ->selectRaw('COALESCE(SUM(received_value), 0) as received_value')
            ->selectRaw('COALESCE(SUM(return_value), 0) as return_value')
            ->selectRaw('COALESCE(SUM(open_lpo_count), 0) as open_lpo_count')
            ->first();

        $paginator = $q->orderByDesc('sp.balance_due')->paginate($perPage);

        return response()->json(array_merge($paginator->toArray(), [
            'summary' => [
                'supplier_count' => (int) ($summaryRaw->supplier_count ?? 0),
                'row_count' => (int) ($summaryRaw->supplier_count ?? 0),
                'balance_due' => round((float) ($summaryRaw->balance_due ?? 0), 2),
                'received_value' => round((float) ($summaryRaw->received_value ?? 0), 2),
                'return_value' => round((float) ($summaryRaw->return_value ?? 0), 2),
                'open_lpo_count' => (int) ($summaryRaw->open_lpo_count ?? 0),
            ],
        ]));
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
