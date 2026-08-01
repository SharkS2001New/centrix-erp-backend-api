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

    public function kpiOccupancy(Request $request)
    {
        return $this->respond($request, 'hospitality-kpi-occupancy');
    }

    public function arrivalsDepartures(Request $request)
    {
        return $this->respond($request, 'hospitality-arrivals-departures');
    }

    public function folioBalances(Request $request)
    {
        return $this->respond($request, 'hospitality-folio-balances');
    }

    public function roomRevenue(Request $request)
    {
        return $this->respond($request, 'hospitality-room-revenue');
    }

    public function fnbChecks(Request $request)
    {
        return $this->respond($request, 'hospitality-fnb-checks');
    }

    public function fnbByOutlet(Request $request)
    {
        return $this->respond($request, 'hospitality-fnb-by-outlet');
    }

    public function fnbByHour(Request $request)
    {
        return $this->respond($request, 'hospitality-fnb-by-hour');
    }

    public function fnbByCategory(Request $request)
    {
        return $this->respond($request, 'hospitality-fnb-by-category');
    }

    public function openChecks(Request $request)
    {
        return $this->respond($request, 'hospitality-open-checks');
    }

    public function voids(Request $request)
    {
        return $this->respond($request, 'hospitality-voids');
    }

    public function managerFlash(Request $request)
    {
        return $this->respond($request, 'hospitality-manager-flash');
    }

    public function profitLoss(Request $request)
    {
        return $this->respond($request, 'hospitality-profit-loss');
    }

    public function eodCashier(Request $request)
    {
        return $this->respond($request, 'hospitality-eod-cashier');
    }

    public function consumptionVariance(Request $request)
    {
        return $this->respond($request, 'hospitality-consumption-variance');
    }

    public function show(Request $request, string $slug)
    {
        return $this->respond($request, $slug);
    }

    protected function respond(Request $request, string $slug)
    {
        $org = $this->erp->resolveOrganization($request);
        if (! in_array($slug, HospitalityReportService::slugs(), true)) {
            abort(404);
        }

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
