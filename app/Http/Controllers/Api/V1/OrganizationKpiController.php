<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationKpi;
use App\Services\Hr\OrganizationKpiService;
use Illuminate\Http\Request;

class OrganizationKpiController extends Controller
{
    public function __construct(private readonly OrganizationKpiService $service) {}

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->overview($request->user()),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $assign = (bool) $request->boolean('assign_to_active');

        $kpi = OrganizationKpi::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
        ]);

        $assigned = $assign ? $this->service->assignToActiveEmployees($kpi) : 0;

        return response()->json([
            'kpi' => $this->service->formatSummary($kpi->fresh()),
            'assigned_count' => $assigned,
        ], 201);
    }

    public function show(Request $request, int $organizationKpi)
    {
        $kpi = $this->findOrgKpi($request, $organizationKpi);

        return response()->json($this->service->formatSummary($kpi));
    }

    public function update(Request $request, int $organizationKpi)
    {
        $kpi = $this->findOrgKpi($request, $organizationKpi);
        $data = $this->validated($request, updating: true);
        $syncEmployees = (bool) $request->boolean('sync_assigned');

        $kpi->update($data);

        if ($syncEmployees) {
            $this->syncAssignedEmployeeKpis($kpi);
        }

        return response()->json($this->service->formatSummary($kpi->fresh()));
    }

    public function destroy(Request $request, int $organizationKpi)
    {
        $this->findOrgKpi($request, $organizationKpi)->delete();

        return response()->json(null, 204);
    }

    public function achievement(Request $request, int $organizationKpi)
    {
        $kpi = $this->findOrgKpi($request, $organizationKpi);

        return response()->json($this->service->achievement($kpi));
    }

    public function assign(Request $request, int $organizationKpi)
    {
        $kpi = $this->findOrgKpi($request, $organizationKpi);
        $assigned = $this->service->assignToActiveEmployees($kpi);

        return response()->json([
            'assigned_count' => $assigned,
            'kpi' => $this->service->formatSummary($kpi->fresh()),
        ]);
    }

    private function findOrgKpi(Request $request, int $id): OrganizationKpi
    {
        return OrganizationKpi::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('id', $id)
            ->firstOrFail();
    }

    private function syncAssignedEmployeeKpis(OrganizationKpi $kpi): void
    {
        $kpi->employeeKpis()->update([
            'kpi_code' => $kpi->kpi_code,
            'label' => $kpi->label,
            'period_start' => $kpi->period_start,
            'period_end' => $kpi->period_end,
            'target_value' => $kpi->target_value,
            'unit' => $kpi->unit,
            'notes' => $kpi->notes,
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'kpi_code' => 'nullable|string|max:64',
            'label' => $req . 'string|max:200',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'target_value' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:2000',
            'is_active' => 'sometimes|boolean',
        ]);
    }
}
