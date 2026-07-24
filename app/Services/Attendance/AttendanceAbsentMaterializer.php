<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Materialize absent attendance for scheduled workdays with no recorded attendance.
 * Payroll already treats missing rows as unpaid; this creates explicit absent rows for
 * registers, HR lists, and audit trails.
 */
class AttendanceAbsentMaterializer
{
    public const AUTO_NOTE = 'Auto-marked absent (no attendance recorded)';

    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
        protected AttendanceDayReconciler $reconciler,
    ) {}

    /**
     * @return array{created_count: int, skipped_count: int, created: list<array{id:int,employee_id:int,attendance_date:string}>, skipped: list<array{employee_id:int,attendance_date:string,reason:string}>}
     */
    public function markRange(?int $organizationId, string $from, string $to): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }

        // Never auto-mark today or future days — the workday may still be in progress.
        $latestAllowed = Carbon::yesterday()->toDateString();
        if ($to > $latestAllowed) {
            $to = $latestAllowed;
        }
        if ($from > $to) {
            return [
                'created_count' => 0,
                'skipped_count' => 0,
                'created' => [],
                'skipped' => [],
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

        $cursor = Carbon::parse($from)->startOfDay();
        $endDay = Carbon::parse($to)->startOfDay();

        while ($cursor->lte($endDay)) {
            $date = $cursor->toDateString();

            foreach ($employees as $employee) {
                $key = $employee->id.'|'.$date;
                if (isset($existing[$key])) {
                    continue;
                }

                $eval = $this->dayPolicy->evaluate($employee, $date);
                if (! ($eval['should_work'] ?? false)) {
                    continue;
                }
                // Full leave/off blocks attendance; half-day leave still expects work —
                // skip both so we don't overwrite leave semantics with a full absent.
                if (($eval['is_leave'] ?? false) || ($eval['blocks_attendance'] ?? false)) {
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
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * Mark absents for a single calendar day (defaults to yesterday).
     *
     * @return array{created_count: int, skipped_count: int, created: list<array{id:int,employee_id:int,attendance_date:string}>, skipped: list<array{employee_id:int,attendance_date:string,reason:string}>}
     */
    public function markDate(?int $organizationId, ?string $date = null): array
    {
        $day = $date
            ? Carbon::parse($date)->toDateString()
            : Carbon::yesterday()->toDateString();

        return $this->markRange($organizationId, $day, $day);
    }
}
