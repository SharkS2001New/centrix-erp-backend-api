<?php

namespace App\Services\Ai\Tools;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Organization;
use App\Models\User;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Validation\ValidationException;

/**
 * Live employee attendance lookup for AI chat (HR register, not a guess).
 */
class GetEmployeeAttendanceTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected UserAccessService $access,
    ) {}

    public function name(): string
    {
        return 'get_employee_attendance';
    }

    public function description(): string
    {
        return 'Look up live Centrix employee attendance (clock in/out, hours, late, status). '
            .'Use when the user asks whether someone is in, who was late/absent, or an employee\'s attendance. '
            .'Pass employee_name (full name, employee code, or login username). '
            .'Use relative_date=today/yesterday/last_7_days. Never invent attendance — always call this tool. '
            .'In the reply, identify people by name and username, never by numeric id.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_name' => [
                    'type' => 'string',
                    'description' => 'Employee full name, employee code, or linked login username.',
                ],
                'relative_date' => [
                    'type' => 'string',
                    'enum' => ['today', 'yesterday', 'last_7_days'],
                    'description' => 'Prefer this for "today", "yesterday", or "this week".',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Single day YYYY-MM-DD when relative_date is not used.',
                ],
                'from_date' => [
                    'type' => 'string',
                    'description' => 'Range start YYYY-MM-DD (inclusive).',
                ],
                'to_date' => [
                    'type' => 'string',
                    'description' => 'Range end YYYY-MM-DD (inclusive).',
                ],
            ],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }

        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'hr.attendance.view', $gate)
            || $this->permissions->hasPermission($user, 'hr.attendance_history.view', $gate);
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view attendance.',
                'screens' => $this->screens(),
            ];
        }

        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $needle = trim((string) ($arguments['employee_name'] ?? ''));

        if ($needle !== '') {
            $resolved = $this->resolveEmployee((int) $organization->id, $needle);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, [
                    'from_date' => $from,
                    'to_date' => $to,
                    'screens' => $this->screens(),
                ]);
            }

            $employee = $resolved['employee'];
            $days = $this->daysForEmployee($user, $organization, (int) $employee->id, $from, $to);

            return [
                'from_date' => $from,
                'to_date' => $to,
                'employee' => $this->presentEmployee($employee),
                'days' => $days,
                'summary' => $this->summarizeDays($days),
                'screens' => $this->screens(),
                'tip' => $days === []
                    ? 'No attendance rows in this date range. Point the user to /hr/attendance or /hr/attendance/history.'
                    : 'Describe clock-in, clock-out, status, and lateness using the employee name and username — never an id.',
            ];
        }

        $days = $this->daysForOrganization($user, $organization, $from, $to);

        return [
            'from_date' => $from,
            'to_date' => $to,
            'summary' => $this->summarizeDays($days),
            'sample' => array_slice($days, 0, 15),
            'screens' => $this->screens(),
            'tip' => 'This is an org snapshot. If the user named a person, call this tool again with employee_name.',
        ];
    }

    /**
     * @return array{error?: bool, message?: string, candidates?: list<array<string, mixed>>, employee?: Employee}
     */
    protected function resolveEmployee(int $organizationId, string $name): array
    {
        $needle = mb_strtolower($name);
        $matches = Employee::query()
            ->with(['user:id,username,full_name'])
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($needle) {
                $query->whereRaw('LOWER(full_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(first_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(employee_code) LIKE ?', ['%'.$needle.'%'])
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
            'candidates' => $matches->map(fn (Employee $row) => $this->presentEmployee($row))->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function daysForEmployee(
        User $user,
        Organization $organization,
        int $employeeId,
        string $from,
        string $to,
    ): array {
        $query = EmployeeAttendance::query()
            ->with(['employee.user:id,username,full_name'])
            ->where('organization_id', $organization->id)
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
        $this->access->applyBranchListFilter($query, $user);

        return $query->orderByDesc('attendance_date')
            ->limit(31)
            ->get()
            ->map(fn (EmployeeAttendance $row) => $this->presentDay($row))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function daysForOrganization(User $user, Organization $organization, string $from, string $to): array
    {
        $query = EmployeeAttendance::query()
            ->with(['employee.user:id,username,full_name'])
            ->where('organization_id', $organization->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
        $this->access->applyBranchListFilter($query, $user);

        return $query->orderByDesc('attendance_date')
            ->orderBy('id')
            ->limit(40)
            ->get()
            ->map(fn (EmployeeAttendance $row) => $this->presentDay($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentEmployee(Employee $employee): array
    {
        $username = $employee->user?->username;

        return array_filter([
            'name' => (string) ($employee->full_name ?: trim($employee->first_name.' '.$employee->last_name)),
            'username' => $username !== null && $username !== '' ? (string) $username : null,
            'employee_code' => $employee->employee_code ? (string) $employee->employee_code : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentDay(EmployeeAttendance $row): array
    {
        $employee = $row->employee;
        $person = $employee ? $this->presentEmployee($employee) : [];

        return array_merge($person, [
            'date' => optional($row->attendance_date)?->toDateString() ?? (string) $row->getRawOriginal('attendance_date'),
            'status' => (string) ($row->status ?? ''),
            'check_in' => $this->formatClock($row->check_in),
            'check_out' => $this->formatClock($row->check_out),
            'hours_worked' => $row->hours_worked !== null ? round((float) $row->hours_worked, 2) : null,
            'late_minutes' => (int) ($row->late_minutes ?? 0),
            'source' => $row->source_label ?: (string) ($row->source ?? ''),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    protected function summarizeDays(array $days): array
    {
        $counts = [];
        $late = 0;
        foreach ($days as $day) {
            $status = (string) ($day['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if ((int) ($day['late_minutes'] ?? 0) > 0 || $status === 'late') {
                $late++;
            }
        }

        return [
            'records' => count($days),
            'by_status' => $counts,
            'late_or_lateness_records' => $late,
        ];
    }

    protected function formatClock(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $raw = (string) $value;
        if (preg_match('/^(\d{1,2}:\d{2})/', $raw, $m)) {
            return $m[1];
        }

        return $raw;
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(): array
    {
        return [
            ['label' => "Today's attendance", 'path' => '/hr/attendance'],
            ['label' => 'Previous attendance', 'path' => '/hr/attendance/history'],
            ['label' => 'Field attendance', 'path' => '/sales/field-attendance'],
        ];
    }
}
