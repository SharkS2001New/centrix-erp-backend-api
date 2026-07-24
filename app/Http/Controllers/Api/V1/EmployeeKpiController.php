<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\FindsOrganizationEmployee;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Services\Hr\EmployeeKpiService;
use Illuminate\Http\Request;

class EmployeeKpiController extends Controller
{
    use FindsOrganizationEmployee;

    public function __construct(private readonly EmployeeKpiService $kpiService) {}

    public function summary(int $employee)
    {
        $model = $this->findOrgEmployee($employee);

        return response()->json($this->kpiService->summary($model));
    }

    public function store(Request $request, int $employee)
    {
        $emp = $this->findOrgEmployee($employee);
        $data = $this->validated($request);
        $data['employee_id'] = $emp->id;
        if ($orgId = $request->user()?->organization_id) {
            $data['organization_id'] = $orgId;
        }

        $kpi = EmployeeKpi::create($data);

        return response()->json($this->formatTracked($kpi), 201);
    }

    public function update(Request $request, int $employee, int $kpi)
    {
        $this->findOrgEmployee($employee);
        $model = EmployeeKpi::where('employee_id', $employee)->findOrFail($kpi);
        $model->update($this->validated($request, updating: true));

        return response()->json($this->formatTracked($model->fresh()));
    }

    public function destroy(int $employee, int $kpi)
    {
        $this->findOrgEmployee($employee);
        EmployeeKpi::where('employee_id', $employee)->findOrFail($kpi)->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function formatTracked(EmployeeKpi $kpi): array
    {
        return [
            'id' => $kpi->id,
            'kpi_code' => $kpi->kpi_code,
            'label' => $kpi->label,
            'period_start' => $kpi->period_start?->format('Y-m-d'),
            'period_end' => $kpi->period_end?->format('Y-m-d'),
            'target_value' => $kpi->target_value !== null ? (float) $kpi->target_value : null,
            'actual_value' => $kpi->actual_value !== null ? (float) $kpi->actual_value : null,
            'unit' => $kpi->unit,
            'notes' => $kpi->notes,
            'progress_pct' => $kpi->progressPercent(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        $req = $updating ? 'sometimes|' : 'required|';

        return $request->validate([
            'kpi_code' => 'nullable|string|max:64',
            'organization_kpi_id' => 'nullable|integer|exists:organization_kpis,id',
            'label' => $req . 'string|max:200',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'target_value' => 'nullable|numeric|min:0',
            'actual_value' => 'nullable|numeric|min:0',
            'unit' => 'nullable|string|max:32',
            'notes' => 'nullable|string|max:2000',
        ]);
    }
}
