<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Models\Employee;

trait ResolvesAiEmployees
{
    /**
     * @return array{error?: bool, message?: string, candidates?: list<array<string, mixed>>, employee?: Employee}
     */
    protected function resolveEmployeeByName(int $organizationId, string $name, bool $withRelations = false): array
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            return [
                'error' => true,
                'message' => 'Provide an employee name, employee code, or login username.',
            ];
        }

        $query = Employee::query()->where('organization_id', $organizationId);
        if ($withRelations) {
            $query->with([
                'user:id,username,full_name',
                'department:id,department_name',
                'position:id,position_title',
                'shift:id,shift_name,shift_code,start_time,end_time,lunch_minutes,lunch_required,crosses_midnight,work_weekdays,works_saturday,works_sunday,works_public_holidays,use_alternate_hours,alternate_start_time,alternate_end_time',
                'branch:id,branch_name',
                'reportsTo:id,full_name,employee_code',
                'bankAccounts',
                'deductions',
            ]);
        } else {
            $query->with(['user:id,username,full_name']);
        }

        $matches = $query
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(full_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(employee_code) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(COALESCE(payroll_number, \'\')) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereHas('user', function ($userQuery) use ($needle) {
                        $userQuery->whereRaw('LOWER(username) LIKE ?', ['%'.$needle.'%'])
                            ->orWhereRaw('LOWER(full_name) LIKE ?', ['%'.$needle.'%']);
                    });
            })
            ->orderBy('full_name')
            ->limit(10)
            ->get();

        if ($matches->isEmpty()) {
            return [
                'error' => true,
                'message' => "No employee matched \"{$name}\" in this organization.",
            ];
        }

        if ($matches->count() === 1) {
            return ['employee' => $matches->first()];
        }

        return [
            'error' => true,
            'message' => "Multiple employees matched \"{$name}\". Ask the user to pick one by name, employee code, or username.",
            'candidates' => $matches->map(fn (Employee $row) => [
                'name' => (string) ($row->full_name ?: trim($row->first_name.' '.$row->last_name)),
                'username' => $row->user?->username,
                'employee_code' => $row->employee_code ? (string) $row->employee_code : null,
            ])->all(),
        ];
    }

    protected function resolveEmployeeById(int $organizationId, int $employeeId, bool $withRelations = true): ?Employee
    {
        if ($employeeId <= 0) {
            return null;
        }

        $query = Employee::query()
            ->where('organization_id', $organizationId)
            ->whereKey($employeeId);

        if ($withRelations) {
            $query->with([
                'user:id,username,full_name',
                'department:id,department_name',
                'position:id,position_title',
                'shift:id,shift_name,shift_code,start_time,end_time,lunch_minutes,lunch_required,crosses_midnight,work_weekdays,works_saturday,works_sunday,works_public_holidays,use_alternate_hours,alternate_start_time,alternate_end_time',
                'branch:id,branch_name',
                'reportsTo:id,full_name,employee_code',
                'bankAccounts',
                'deductions',
            ]);
        }

        return $query->first();
    }
}
