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

        $data = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        return response()->json($this->dashboard->summary($org, [
            'from_date' => $data['from_date'] ?? $request->input('from') ?? $request->input('sale_date'),
            'to_date' => $data['to_date'] ?? $request->input('to') ?? $request->input('sale_date'),
        ]));
    }
}
