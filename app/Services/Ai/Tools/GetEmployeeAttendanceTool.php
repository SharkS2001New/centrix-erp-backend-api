<?php

namespace App\Services\Ai\Tools;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkShift;
use App\Services\Ai\AiSalesDateResolver;
use App\Services\Ai\Tools\Concerns\ResolvesAiEmployees;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserAccessService;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Payroll\PayrollEarningsService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Live employee attendance lookup for AI chat (HR register, not a guess).
 */
class GetEmployeeAttendanceTool implements AiToolInterface
{
    use ResolvesAiEmployees;
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
            .'Use relative_date=today/yesterday/last_7_days/this_month/last_month, or year_month=YYYY-MM, or from_date/to_date. '
            .'For "who was late" / unwaived lateness questions, set late_only=true (and unwaived_only=true when they ask for unwaived). '
            .'Never invent attendance — always call this tool. '
            .'Each day includes scheduled shift hours for that weekday — Saturday/Sunday may use alternate shorter hours; '
            .'those are full roster days, not "half-days". '
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
                    'enum' => ['today', 'yesterday', 'last_7_days', 'this_month', 'last_month', 'this_month_to_date'],
                    'description' => 'Prefer this for "today", "yesterday", "this week", or a full calendar month.',
                ],
                'year_month' => [
                    'type' => 'string',
                    'description' => 'Calendar month YYYY-MM (e.g. 2026-08).',
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
                'late_only' => [
                    'type' => 'boolean',
                    'description' => 'When true, return only late attendance (clock-in or lunch late, or status=late). Use for "who was late".',
                ],
                'unwaived_only' => [
                    'type' => 'boolean',
                    'description' => 'When true, exclude waived lateness. Use for "unwaived lateness". Implies late_only.',
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
                'screens' => $this->screens(true),
            ];
        }

        [$from, $to] = AiSalesDateResolver::resolve($arguments, $organization);
        $needle = trim((string) ($arguments['employee_name'] ?? ''));
        $unwaivedOnly = $this->truthy($arguments['unwaived_only'] ?? false);
        $lateOnly = $unwaivedOnly || $this->truthy($arguments['late_only'] ?? false);
        $latenessFocus = $lateOnly || $unwaivedOnly;

        if ($needle !== '') {
            $resolved = $this->resolveEmployeeByName((int) $organization->id, $needle);
            if (($resolved['error'] ?? false) === true) {
                return array_merge($resolved, [
                    'from_date' => $from,
                    'to_date' => $to,
                    'late_only' => $lateOnly,
                    'unwaived_only' => $unwaivedOnly,
                    'screens' => $this->screens($latenessFocus),
                ]);
            }

            $employee = $resolved['employee'];
            $employee->loadMissing([
                'shift:id,shift_name,shift_code,start_time,end_time,lunch_minutes,lunch_required,crosses_midnight,work_weekdays,works_saturday,works_sunday,works_public_holidays,use_alternate_hours,alternate_start_time,alternate_end_time,alternate_lunch_minutes,alternate_lunch_required,alternate_crosses_midnight',
            ]);
            $days = $this->daysForEmployee(
                $user,
                $organization,
                (int) $employee->id,
                $from,
                $to,
                $employee->shift,
                $lateOnly,
                $unwaivedOnly,
            );

            return [
                'from_date' => $from,
                'to_date' => $to,
                'late_only' => $lateOnly,
                'unwaived_only' => $unwaivedOnly,
                'employee' => $this->presentEmployee($employee),
                'shift' => $employee->shift ? $employee->shift->presentForAi() : null,
                'days' => $days,
                'summary' => $this->summarizeDays($days),
                'screens' => $this->screens($latenessFocus),
                'tip' => $days === []
                    ? ($lateOnly
                        ? 'No late rows in this date range. Point the user to /hr/lateness or /hr/attendance/history.'
                        : 'No attendance rows in this date range. Point the user to /hr/attendance or /hr/attendance/history.')
                    : 'Describe clock-in, clock-out, status, and lateness using the employee name and username — never an id. '
                        .'Use shift.schedule_by_day. If Saturday/Sunday used alternate hours, say "scheduled Saturday shift" — never "half-day". '
                        .'Mention waived vs unwaived when lateness_waived is set.',
            ];
        }

        $days = $this->daysForOrganization($user, $organization, $from, $to, $lateOnly, $unwaivedOnly);
        $sampleLimit = $lateOnly ? 40 : 15;

        return [
            'from_date' => $from,
            'to_date' => $to,
            'late_only' => $lateOnly,
            'unwaived_only' => $unwaivedOnly,
            'summary' => $this->summarizeDays($days),
            'sample' => array_slice($days, 0, $sampleLimit),
            'screens' => $this->screens($latenessFocus),
            'tip' => $lateOnly
                ? 'These rows are late attendance only. List name + username + late minutes. Prefer /hr/lateness for the full queue. Never invent people.'
                : 'This is an org snapshot. If the user named a person, call this tool again with employee_name. '
                    .'For "who was late", call again with late_only=true (and unwaived_only=true when asked).',
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
        ?WorkShift $shift = null,
        bool $lateOnly = false,
        bool $unwaivedOnly = false,
    ): array {
        $query = EmployeeAttendance::query()
            ->with(['employee.user:id,username,full_name'])
            ->where('organization_id', $organization->id)
            ->where('employee_id', $employeeId)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
        $this->access->applyBranchListFilter($query, $user);
        $this->applyLatenessFilters($query, $lateOnly, $unwaivedOnly);

        return $query->orderByDesc('attendance_date')
            ->limit(62)
            ->get()
            ->map(fn (EmployeeAttendance $row) => $this->presentDay($row, $shift))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function daysForOrganization(
        User $user,
        Organization $organization,
        string $from,
        string $to,
        bool $lateOnly = false,
        bool $unwaivedOnly = false,
    ): array {
        $query = EmployeeAttendance::query()
            ->with(['employee.user:id,username,full_name', 'employee.shift'])
            ->where('organization_id', $organization->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to);
        $this->access->applyBranchListFilter($query, $user);
        $this->applyLatenessFilters($query, $lateOnly, $unwaivedOnly);

        $limit = $lateOnly ? 100 : 40;
        if ($lateOnly) {
            $query
                ->orderByRaw('(COALESCE(late_minutes, 0) + COALESCE(lunch_late_minutes, 0)) DESC')
                ->orderByDesc('attendance_date')
                ->orderBy('id');
        } else {
            $query->orderByDesc('attendance_date')->orderBy('id');
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(fn (EmployeeAttendance $row) => $this->presentDay($row, $row->employee?->shift))
            ->all();
    }

    /**
     * @param  Builder<EmployeeAttendance>  $query
     */
    protected function applyLatenessFilters(Builder $query, bool $lateOnly, bool $unwaivedOnly): void
    {
        if ($lateOnly) {
            $grace = PayrollEarningsService::HOURLY_DEDUCTION_MINUTE_GRACE;
            $query->where(function (Builder $inner) use ($grace) {
                $inner->where('late_minutes', '>', 0)
                    ->orWhere('lunch_late_minutes', '>', 0)
                    ->orWhere('early_leave_minutes', '>', $grace)
                    ->orWhere('status', 'late');
            });
        }
        if ($unwaivedOnly) {
            $query->where(function (Builder $inner) {
                $inner->whereNull('lateness_waived')
                    ->orWhere('lateness_waived', false);
            });
        }
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
    protected function presentDay(EmployeeAttendance $row, ?WorkShift $shift = null): array
    {
        $employee = $row->employee;
        $person = $employee ? $this->presentEmployee($employee) : [];
        $date = optional($row->attendance_date)?->toDateString() ?? (string) $row->getRawOriginal('attendance_date');
        $day = Carbon::parse($date);
        $scheduled = $shift ? $shift->hoursForDate($date) : null;
        $isWeekend = $day->isSaturday() || $day->isSunday();
        $usesAlternate = $shift
            && (bool) ($shift->use_alternate_hours ?? false)
            && $shift->alternate_start_time
            && $shift->alternate_end_time
            && $isWeekend;

        $clockInLate = (int) ($row->late_minutes ?? 0);
        $lunchLate = (int) ($row->lunch_late_minutes ?? 0);

        return array_merge($person, [
            'date' => $date,
            'weekday' => $day->format('l'),
            'status' => (string) ($row->status ?? ''),
            'check_in' => $this->formatClock($row->check_in),
            'check_out' => $this->formatClock($row->check_out),
            'hours_worked' => $row->hours_worked !== null ? round((float) $row->hours_worked, 2) : null,
            'late_minutes' => $clockInLate,
            'lunch_late_minutes' => $lunchLate,
            'total_late_minutes' => $clockInLate + $lunchLate,
            'lateness_waived' => (bool) ($row->lateness_waived ?? false),
            'source' => $row->source_label ?: (string) ($row->source ?? ''),
            'scheduled_start' => $scheduled ? $this->formatClock($scheduled['start_time'] ?? null) : null,
            'scheduled_end' => $scheduled ? $this->formatClock($scheduled['end_time'] ?? null) : null,
            'scheduled_lunch_minutes' => $scheduled ? (int) ($scheduled['lunch_minutes'] ?? 0) : null,
            'uses_alternate_shift_hours' => $usesAlternate,
            'schedule_note' => $usesAlternate
                ? 'Scheduled weekend/alternate shift hours for this day — not a half-day.'
                : null,
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
        $unwaivedLate = 0;
        $weekendScheduled = 0;
        foreach ($days as $day) {
            $status = (string) ($day['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            $totalLate = (int) ($day['total_late_minutes'] ?? 0);
            if ($totalLate <= 0) {
                $totalLate = (int) ($day['late_minutes'] ?? 0) + (int) ($day['lunch_late_minutes'] ?? 0);
            }
            $isLate = $totalLate > 0 || $status === 'late';
            if ($isLate) {
                $late++;
                if (empty($day['lateness_waived'])) {
                    $unwaivedLate++;
                }
            }
            if (! empty($day['uses_alternate_shift_hours'])) {
                $weekendScheduled++;
            }
        }

        return [
            'records' => count($days),
            'by_status' => $counts,
            'late_or_lateness_records' => $late,
            'unwaived_late_records' => $unwaivedLate,
            'weekend_or_alternate_shift_days' => $weekendScheduled,
            'note' => $weekendScheduled > 0
                ? 'Some days used Saturday/Sunday alternate shift hours. Those are full scheduled roster days — do not describe them as half-days.'
                : null,
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

    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }
        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<array{label: string, path: string}>
     */
    protected function screens(bool $latenessFocus = false): array
    {
        $screens = [
            ['label' => "Today's attendance", 'path' => '/hr/attendance'],
            ['label' => 'Previous attendance', 'path' => '/hr/attendance/history'],
            ['label' => 'Lateness', 'path' => '/hr/lateness'],
            ['label' => 'Field attendance', 'path' => '/sales/field-attendance'],
        ];
        if ($latenessFocus) {
            return [
                ['label' => 'Lateness', 'path' => '/hr/lateness'],
                ['label' => 'Previous attendance', 'path' => '/hr/attendance/history'],
                ['label' => "Today's attendance", 'path' => '/hr/attendance'],
            ];
        }

        return $screens;
    }
}
