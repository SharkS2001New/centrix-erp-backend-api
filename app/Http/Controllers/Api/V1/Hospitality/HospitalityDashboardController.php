<?php

namespace App\Http\Controllers\Api\V1\Hospitality;

use App\Http\Controllers\Controller;
use App\Services\Erp\ErpContext;
use App\Services\Hospitality\HospitalityDashboardService;
use Illuminate\Http\Request;

class HospitalityDashboardController extends Controller
{
    public function __construct(
        protected ErpContext $erp,
        protected HospitalityDashboardService $dashboard,
    ) {}

    public function summary(Request $request)
    {
        $org = $this->erp->resolveOrganization($request);

        return response()->json($this->dashboard->summary($org));
    }
}
