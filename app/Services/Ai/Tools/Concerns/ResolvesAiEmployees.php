<?php

namespace App\Services\Ai\Tools\Concerns;

use App\Models\Employee;
use App\Services\Ai\AiNearMissHelper;

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

        $matches = $this->employeeNameQuery($organizationId, $needle, $withRelations)->get();

        if ($matches->isEmpty()) {
            $matches = $this->employeeRelaxedQuery($organizationId, $name, $withRelations)->get();
        }

        if ($matches->isEmpty()) {
            return AiNearMissHelper::noExact(
                $name,
                null,
                [],
                [['label' => 'Employees', 'path' => '/hr/employees']],
                'Open the employees list to verify the name or employee code.',
            );
        }

        if ($matches->count() === 1) {
            return ['employee' => $matches->first()];
        }

        return AiNearMissHelper::ambiguous(
            $name,
            $matches->map(fn (Employee $row) => [
                'label' => (string) ($row->full_name ?: trim($row->first_name.' '.$row->last_name)),
                'username' => $row->user?->username,
                'employee_code' => $row->employee_code ? (string) $row->employee_code : null,
            ])->all(),
            'employee',
        );
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Employee>
     */
    protected function employeeNameQuery(int $organizationId, string $needle, bool $withRelations)
    {
        $query = Employee::query()->where('organization_id', $organizationId);
        if ($withRelations) {
            $query->with([
                'user:id,username,full_name,assigned_route_id',
                'user.assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
                'department:id,department_name',
                'position:id,position_title',
                'shift:id,shift_name,shift_code,start_time,end_time,lunch_minutes,lunch_required,crosses_midnight,work_weekdays,works_saturday,works_sunday,works_public_holidays,use_alternate_hours,alternate_start_time,alternate_end_time',
                'branch:id,branch_name',
                'reportsTo:id,full_name,employee_code',
                'bankAccounts',
                'deductions',
            ]);
        } else {
            $query->with([
                'user:id,username,full_name,assigned_route_id',
                'user.assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
            ]);
        }

        return $query
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
            ->limit(10);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<Employee>
     */
    protected function employeeRelaxedQuery(int $organizationId, string $name, bool $withRelations)
    {
        $tokens = AiNearMissHelper::tokens($name);
        if ($tokens === []) {
            return Employee::query()->whereRaw('1 = 0');
        }

        $query = Employee::query()->where('organization_id', $organizationId);
        if ($withRelations) {
            $query->with([
                'user:id,username,full_name,assigned_route_id',
                'user.assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
            ]);
        }

        return $query
            ->where(function ($q) use ($tokens) {
                foreach ($tokens as $token) {
                    if (mb_strlen($token) < 3) {
                        continue;
                    }
                    $like = '%'.$token.'%';
                    $q->orWhereRaw('LOWER(full_name) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(employee_code) LIKE ?', [$like]);
                }
            })
            ->orderBy('full_name')
            ->limit(10);
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
                'user:id,username,full_name,assigned_route_id',
                'user.assignedRoutes:id,organization_id,branch_id,route_name,direction,is_active',
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
