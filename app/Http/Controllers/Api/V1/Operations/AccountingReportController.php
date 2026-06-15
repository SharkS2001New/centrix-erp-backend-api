<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingReportController extends Controller
{
    public function __construct(protected AccountingReportService $reports) {}

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
        $result = $this->reports->cashFlow((int) $request->user()->organization_id, $this->filters($request));

        return response()->json([
            'data' => $result['rows'],
            'summary' => $result['summary'],
        ]);
    }

    public function accountsReceivable(Request $request)
    {
        $q = DB::table('v_accounts_receivable_summary');
        foreach (['customer_num'] as $col) {
            if ($request->filled($col)) {
                $q->where($col, $request->input($col));
            }
        }

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

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        return $request->only([
            'branch_id', 'account_id', 'from_date', 'to_date', 'per_page', 'page',
        ]);
    }
}
