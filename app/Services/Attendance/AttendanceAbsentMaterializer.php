<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Materialize absent attendance for scheduled workdays with no recorded attendance.
 * Payroll already treats missing rows as unpaid; this creates explicit absent rows for
 * registers, HR lists, and audit trails.
 *
 * Days outside the employee's shift / work_weekdays are never marked absent.
 */
class AttendanceAbsentMaterializer
{
    public const AUTO_NOTE = 'Auto-marked absent (no attendance recorded)';

    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected AttendanceDayReconciler $reconciler,
    ) {}

    /**
     * @return array{
     *   created_count: int,
     *   skipped_count: int,
     *   removed_count: int,
     *   created: list<array{id:int,employee_id:int,attendance_date:string}>,
     *   skipped: list<array{employee_id:int,attendance_date:string,reason:string}>,
     *   removed: list<array{id:int,employee_id:int,attendance_date:string}>
     * }
     */
    public function markRange(?int $organizationId, string $from, string $to): array
    {
        $from = Carbon::parse($from, AppTimezone::name())->toDateString();
        $to = Carbon::parse($to, AppTimezone::name())->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        // Never auto-mark today or future days — the workday may still be in progress.
        $latestAllowed = AppTimezone::now()->subDay()->toDateString();
        if ($to > $latestAllowed) {
            $to = $latestAllowed;
        }
        if ($from > $to) {
            return [
                'created_count' => 0,
                'skipped_count' => 0,
                'removed_count' => 0,
                'created' => [],
                'skipped' => [],
                'removed' => [],
            ];
        }

        $employeesQuery = Employee::query()
            ->with('shift')
            ->whereNotNull('shift_id')
            ->where(function ($q) {
                $q->where('is_active', '!=', false)->orWhereNull('is_active');
            })
            ->where('employment_status', 'active');

        if ($organizationId) {
            $employeesQuery->where('organization_id', $organizationId);
        }

        /** @var Collection<int, Employee> $employees */
        $employees = $employeesQuery->orderBy('id')->get();
        $this->dayPolicy->primeScheduleContext($employees, $from, $to);

        $removed = $this->purgeUnscheduledAutoAbsents($organizationId, $employees, $from, $to);

        $existing = EmployeeAttendance::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->get(['employee_id', 'attendance_date'])
            ->mapWithKeys(function ($row) {
                $date = $row->attendance_date instanceof Carbon
                    ? $row->attendance_date->toDateString()
                    : (string) $row->attendance_date;

                return [$row->employee_id.'|'.$date => true];
            });

        $created = [];
        $skipped = [];

        $cursor = Carbon::parse($from, AppTimezone::name())->startOfDay();
        $endDay = Carbon::parse($to, AppTimezone::name())->startOfDay();

        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();

            foreach ($employees as $employee) {
                $key = $employee->id.'|'.$date;
                if (isset($existing[$key])) {
                    continue;
                }

                if (! $this->dayPolicy->isScheduledWorkday($employee, $date)) {
                    continue;
                }

                $eval = $this->dayPolicy->evaluate($employee, $date);
                // Full leave/off blocks attendance; half-day leave still expects work —
                // skip both so we don't overwrite leave semantics with a full absent.
                if (($eval['is_leave'] ?? false) || ($eval['blocks_attendance'] ?? false)) {
                    continue;
                }
                if (! ($eval['should_work'] ?? false)) {
                    continue;
                }

                try {
                    $row = $this->reconciler->reconcileManualSpan(
                        $employee,
                        $date,
                        null,
                        null,
                        'manual',
                        null,
                        $employee->branch_id ? (int) $employee->branch_id : null,
                        self::AUTO_NOTE,
                        'absent',
                        null,
                    );
                    $existing[$key] = true;
                    $created[] = [
                        'id' => (int) $row->id,
                        'employee_id' => (int) $employee->id,
                        'attendance_date' => $date,
                    ];
                } catch (\Throwable $e) {
                    $skipped[] = [
                        'employee_id' => (int) $employee->id,
                        'attendance_date' => $date,
                        'reason' => $e->getMessage() ?: 'Could not mark absent',
                    ];
                }
            }

            $cursor->addDay();
        }

        $this->dayPolicy->clearScheduleContext();

        return [
            'created_count' => count($created),
            'skipped_count' => count($skipped),
            'removed_count' => count($removed),
            'created' => $created,
            'skipped' => $skipped,
            'removed' => $removed,
        ];
    }

    /**
     * Mark absents for a single calendar day (defaults to yesterday).
     *
     * @return array{
     *   created_count: int,
     *   skipped_count: int,
     *   removed_count: int,
     *   created: list<array{id:int,employee_id:int,attendance_date:string}>,
     *   skipped: list<array{employee_id:int,attendance_date:string,reason:string}>,
     *   removed: list<array{id:int,employee_id:int,attendance_date:string}>
     * }
     */
    public function markDate(?int $organizationId, ?string $date = null): array
    {
        $day = $date
            ? Carbon::parse($date, AppTimezone::name())->toDateString()
            : AppTimezone::now()->subDay()->toDateString();

        return $this->markRange($organizationId, $day, $day);
    }

    /**
     * Drop auto-marked absents that fall on days the employee is not scheduled to work
     * (e.g. shift work days changed after absents were created).
     *
     * @param  Collection<int, Employee>  $employees
     * @return list<array{id:int,employee_id:int,attendance_date:string}>
     */
    protected function purgeUnscheduledAutoAbsents(
        ?int $organizationId,
        Collection $employees,
        string $from,
        string $to,
    ): array {
        if ($employees->isEmpty()) {
            return [];
        }

        $byId = $employees->keyBy('id');
        $rows = EmployeeAttendance::query()
            ->when($organizationId, fn ($q) => $q->where('organization_id', $organizationId))
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->where('status', 'absent')
            ->where('notes', self::AUTO_NOTE)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->get();

        $removed = [];
        foreach ($rows as $row) {
            $employee = $byId->get((int) $row->employee_id);
            if (! $employee) {
                continue;
            }
            $date = $row->attendance_date instanceof Carbon
                ? $row->attendance_date->toDateString()
                : (string) $row->attendance_date;

            if ($this->dayPolicy->isScheduledWorkday($employee, $date)) {
                continue;
            }

            $removed[] = [
                'id' => (int) $row->id,
                'employee_id' => (int) $row->employee_id,
                'attendance_date' => $date,
            ];
            $row->delete();
        }

        return $removed;
    }
}
