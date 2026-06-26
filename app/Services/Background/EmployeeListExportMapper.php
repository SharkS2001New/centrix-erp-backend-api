<?php

namespace App\Services\Background;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Position;
use App\Models\WorkShift;
use Illuminate\Support\Collection;

class EmployeeListExportMapper implements ListExportRowMapper
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function mapBatch(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $lookups = $this->loadLookups($rows);

        return array_map(fn (array $row) => $this->mapOne($row, $lookups), $rows);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{
     *     departments: Collection<int|string, Department>,
     *     positions: Collection<int|string, Position>,
     *     shifts: Collection<int|string, WorkShift>,
     *     branches: Collection<int|string, Branch>
     * }
     */
    protected function loadLookups(array $rows): array
    {
        return [
            'departments' => $this->loadIds($rows, 'department_id', Department::class),
            'positions' => $this->loadIds($rows, 'position_id', Position::class),
            'shifts' => $this->loadIds($rows, 'shift_id', WorkShift::class),
            'branches' => $this->loadIds($rows, 'branch_id', Branch::class),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  class-string  $modelClass
     */
    protected function loadIds(array $rows, string $field, string $modelClass): Collection
    {
        $ids = [];
        foreach ($rows as $row) {
            $nested = $row[$this->nestedKey($field)] ?? null;
            if (is_array($nested) && isset($nested['id'])) {
                $ids[] = (int) $nested['id'];
            }
            $id = $row[$field] ?? null;
            if ($id !== null && $id !== '') {
                $ids[] = (int) $id;
            }
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return collect();
        }

        return $modelClass::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    protected function nestedKey(string $field): string
    {
        return match ($field) {
            'department_id' => 'department',
            'position_id' => 'position',
            'shift_id' => 'shift',
            'branch_id' => 'branch',
            default => rtrim($field, '_id'),
        };
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{
     *     departments: Collection<int|string, Department>,
     *     positions: Collection<int|string, Position>,
     *     shifts: Collection<int|string, WorkShift>,
     *     branches: Collection<int|string, Branch>
     * }  $lookups
     * @return array<string, mixed>
     */
    protected function mapOne(array $row, array $lookups): array
    {
        $department = $this->resolveRelation($row, 'department', 'department_id', $lookups['departments']);
        $position = $this->resolveRelation($row, 'position', 'position_id', $lookups['positions']);
        $shift = $this->resolveRelation($row, 'shift', 'shift_id', $lookups['shifts']);
        $branch = $this->resolveRelation($row, 'branch', 'branch_id', $lookups['branches']);

        return [
            'employee_code' => $row['employee_code'] ?? '',
            'payroll_number' => $row['payroll_number'] ?? '',
            'full_name' => $row['full_name'] ?? '',
            'first_name' => $row['first_name'] ?? '',
            'last_name' => $row['last_name'] ?? '',
            'job_title' => $row['job_title'] ?? '',
            'department_name' => $department?->department_name ?? (is_array($row['department'] ?? null) ? ($row['department']['department_name'] ?? '') : ''),
            'position_name' => $position?->position_title ?? (is_array($row['position'] ?? null) ? ($row['position']['position_title'] ?? $row['position']['position_name'] ?? '') : ''),
            'shift_name' => $shift?->shift_name ?? (is_array($row['shift'] ?? null) ? ($row['shift']['shift_name'] ?? '') : ''),
            'branch_name' => $branch?->branch_name ?? (is_array($row['branch'] ?? null) ? ($row['branch']['branch_name'] ?? '') : ''),
            'email' => $row['email'] ?? $row['personal_email'] ?? '',
            'phone' => $row['phone'] ?? '',
            'employment_status' => $row['employment_status'] ?? '',
            'employment_type' => $row['employment_type'] ?? '',
            'hire_date' => $row['hire_date'] ?? '',
            'base_salary' => $row['base_salary'] ?? '',
            'kra_pin' => $row['kra_pin'] ?? '',
            'nssf_number' => $row['nssf_number'] ?? '',
            'sha_number' => $row['sha_number'] ?? '',
            'is_active' => ! empty($row['is_active']) ? 'Yes' : 'No',
        ];
    }

    protected function resolveRelation(array $row, string $nestedKey, string $idKey, Collection $lookup): mixed
    {
        $nested = $row[$nestedKey] ?? null;
        if (is_array($nested) && isset($nested['id'])) {
            return $lookup->get($nested['id']) ?? (object) $nested;
        }

        $id = $row[$idKey] ?? null;

        return $id !== null && $id !== '' ? $lookup->get((int) $id) : null;
    }
}
