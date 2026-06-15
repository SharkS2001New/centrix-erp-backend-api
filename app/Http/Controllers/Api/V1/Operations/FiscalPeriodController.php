<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Controller;
use App\Models\FiscalPeriod;
use App\Services\Accounting\FiscalPeriodService;
use Illuminate\Http\Request;

class FiscalPeriodController extends Controller
{
    public function __construct(
        protected FiscalPeriodService $periods,
    ) {}

    public function index(Request $request)
    {
        $year = $request->filled('year') ? (int) $request->input('year') : null;
        $orgId = (int) $request->user()->organization_id;

        return response()->json([
            'data' => $this->periods->listForOrganization($orgId, $year),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'year' => 'required|integer|min:2000|max:2100',
        ]);

        $orgId = (int) $request->user()->organization_id;
        $this->periods->seedYear($orgId, (int) $data['year']);

        return response()->json([
            'data' => $this->periods->listForOrganization($orgId, (int) $data['year']),
        ], 201);
    }

    public function close(Request $request, int $periodId)
    {
        $period = $this->findOrgPeriod($request, $periodId);

        return response()->json($this->periods->close($period, $request->user()));
    }

    public function reopen(Request $request, int $periodId)
    {
        $period = $this->findOrgPeriod($request, $periodId);

        return response()->json($this->periods->reopen($period));
    }

    protected function findOrgPeriod(Request $request, int $periodId): FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $periodId)
            ->firstOrFail();
    }
}
