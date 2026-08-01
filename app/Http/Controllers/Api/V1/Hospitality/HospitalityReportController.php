<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityReportService;
use Illuminate\Http\Request;

class HospitalityReportController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityReportService $reports,
    ) {}

    public function occupancy(Request $request)
    {
        return $this->respond($request, 'hospitality-occupancy');
    }

    public function arrivalsDepartures(Request $request)
    {
        return $this->respond($request, 'hospitality-arrivals-departures');
    }

    public function folioBalances(Request $request)
    {
        return $this->respond($request, 'hospitality-folio-balances');
    }

    public function fnbChecks(Request $request)
    {
        return $this->respond($request, 'hospitality-fnb-checks');
    }

    public function profitLoss(Request $request)
    {
        return $this->respond($request, 'hospitality-profit-loss');
    }

    public function eodCashier(Request $request)
    {
        return $this->respond($request, 'hospitality-eod-cashier');
    }

    public function show(Request $request, string $slug)
    {
        return $this->respond($request, $slug);
    }

    protected function respond(Request $request, string $slug)
    {
        $org = $this->erp->resolveOrganization($request);
        $allowed = [
            'hospitality-occupancy',
            'hospitality-arrivals-departures',
            'hospitality-folio-balances',
            'hospitality-fnb-checks',
            'hospitality-profit-loss',
            'hospitality-eod-cashier',
        ];
        if (! in_array($slug, $allowed, true)) {
            abort(404);
        }

        // EOD defaults to a single day when only one date is provided.
        $from = $request->input('from') ?? $request->input('sale_date') ?? $request->input('from_date');
        $to = $request->input('to') ?? $request->input('sale_date') ?? $request->input('to_date') ?? $from;

        $result = $this->reports->run($org, $slug, $from, $to);

        return response()->json([
            'data' => $result['rows'] ?? [],
            'columns' => $result['columns'] ?? [],
            'total' => count($result['rows'] ?? []),
        ]);
    }
}
