<?php

namespace App\Services\Hr;

use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\OrganizationKpi;
use App\Models\User;
use Illuminate\Support\Collection;

class OrganizationKpiService
{
    /** @return list<array<string, mixed>> */
    public function overview(User $user): array
    {
        return OrganizationKpi::query()
            ->where('organization_id', $user->organization_id)
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->get()
            ->map(fn (OrganizationKpi $kpi) => $this->formatSummary($kpi))
            ->all();
    }

    /** @return array<string, mixed> */
    public function achievement(OrganizationKpi $kpi): array
    {
        $rows = EmployeeKpi::query()
            ->where('organization_kpi_id', $kpi->id)
            ->with(['employee.department', 'employee.position'])
            ->orderBy('employee_id')
            ->get();

        $employees = $rows->map(function (EmployeeKpi $row) {
            $progress = $row->progressPercent();
            $target = $row->target_value !== null ? (float) $row->target_value : null;
            $actual = $row->actual_value !== null ? (float) $row->actual_value : null;

            return [
                'employee_id' => $row->employee_id,
                'employee_code' => $row->employee?->employee_code,
                'employee_name' => $row->employee?->full_name,
                'department_name' => $row->employee?->department?->department_name,
                'position_title' => $row->employee?->position?->position_title,
                'employment_status' => $row->employee?->employment_status,
                'is_active' => (bool) $row->employee?->is_active,
                'employee_kpi_id' => $row->id,
                'target_value' => $target,
                'actual_value' => $actual,
                'unit' => $row->unit,
                'progress_pct' => $progress,
                'status' => $this->achievementStatus($progress, $target, $actual),
            ];
        })->values()->all();

        $counts = collect($employees)->countBy('status');

        return [
            'kpi' => $this->formatSummary($kpi),
            'employees' => $employees,
            'summary' => [
                'assigned' => count($employees),
                'met' => (int) ($counts->get('met') ?? 0),
                'in_progress' => (int) ($counts->get('in_progress') ?? 0),
                'not_met' => (int) ($counts->get('not_met') ?? 0),
                'no_data' => (int) ($counts->get('no_data') ?? 0),
            ],
        ];
    }

    public function assignToActiveEmployees(OrganizationKpi $kpi): int
    {
        $employees = Employee::query()
            ->where('organization_id', $kpi->organization_id)
            ->where('is_active', true)
            ->where('employment_status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'organization_id']);

        $assigned = 0;
        foreach ($employees as $employee) {
            $existing = EmployeeKpi::query()
                ->where('employee_id', $employee->id)
                ->where('organization_kpi_id', $kpi->id)
                ->first();

            EmployeeKpi::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'organization_kpi_id' => $kpi->id,
                ],
                [
                    'organization_id' => $kpi->organization_id,
                    'kpi_code' => $kpi->kpi_code,
                    'label' => $kpi->label,
                    'period_start' => $kpi->period_start,
                    'period_end' => $kpi->period_end,
                    'target_value' => $kpi->target_value,
                    'unit' => $kpi->unit,
                    'notes' => $kpi->notes,
                    'actual_value' => $existing?->actual_value,
                ],
            );
            $assigned++;
        }

        return $assigned;
    }

    /** @return array<string, mixed> */
    public function formatSummary(OrganizationKpi $kpi): array
    {
        $assigned = EmployeeKpi::query()->where('organization_kpi_id', $kpi->id)->count();
        $achievement = $this->achievementCounts($kpi->id);

        return [
            'id' => $kpi->id,
            'kpi_code' => $kpi->kpi_code,
            'label' => $kpi->label,
            'period_start' => $kpi->period_start?->format('Y-m-d'),
            'period_end' => $kpi->period_end?->format('Y-m-d'),
            'target_value' => $kpi->target_value !== null ? (float) $kpi->target_value : null,
            'unit' => $kpi->unit,
            'notes' => $kpi->notes,
            'is_active' => (bool) $kpi->is_active,
            'assigned_count' => $assigned,
            'met_count' => $achievement['met'],
            'in_progress_count' => $achievement['in_progress'],
            'not_met_count' => $achievement['not_met'],
            'no_data_count' => $achievement['no_data'],
        ];
    }

    /** @return array{met: int, in_progress: int, not_met: int, no_data: int} */
    private function achievementCounts(int $organizationKpiId): array
    {
        $counts = ['met' => 0, 'in_progress' => 0, 'not_met' => 0, 'no_data' => 0];

        EmployeeKpi::query()
            ->where('organization_kpi_id', $organizationKpiId)
            ->get(['target_value', 'actual_value'])
            ->each(function (EmployeeKpi $row) use (&$counts) {
                $status = $this->achievementStatus(
                    $row->progressPercent(),
                    $row->target_value !== null ? (float) $row->target_value : null,
                    $row->actual_value !== null ? (float) $row->actual_value : null,
                );
                $counts[$status]++;
            });

        return $counts;
    }

    private function achievementStatus(?float $progress, ?float $target, ?float $actual): string
    {
        if ($target === null || $target <= 0 || $actual === null) {
            return 'no_data';
        }
        if ($progress === null) {
            return 'no_data';
        }
        if ($progress >= 100) {
            return 'met';
        }
        if ($progress > 0) {
            return 'in_progress';
        }

        return 'not_met';
    }
}
