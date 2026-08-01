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

    public function show(Request $request, string $slug)
    {
        $org = $this->erp->resolveOrganization($request);
        $allowed = [
            'hospitality-occupancy',
            'hospitality-arrivals-departures',
            'hospitality-folio-balances',
            'hospitality-fnb-checks',
        ];
        if (! in_array($slug, $allowed, true)) {
            abort(404);
        }

        return response()->json(
            $this->reports->run(
                $org,
                $slug,
                $request->input('from'),
                $request->input('to'),
            )
        );
    }
}
