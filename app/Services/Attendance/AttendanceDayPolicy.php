<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeLeaveDay;
use App\Models\OrganizationHoliday;
use App\Models\WorkShift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceDayPolicy
{
    /** @var array<int, array<string, OrganizationHoliday>> */
    protected array $holidayDatesByOrg = [];

    /** @var array<int, WorkShift> */
    protected array $shiftCache = [];

    /**
     * @param  Collection<int, Employee>  $employees
     */
    public function primeScheduleContext(Collection $employees, string $start, string $end): void
    {
        $this->clearScheduleContext();

        $orgIds = $employees->pluck('organization_id')->filter()->unique()->values()->all();
        if ($orgIds !== []) {
            $holidays = OrganizationHoliday::query()
                ->whereIn('organization_id', $orgIds)
                ->where('is_active', true)
                ->whereDate('holiday_date', '>=', $start)
                ->whereDate('holiday_date', '<=', $end)
                ->get();

            foreach ($holidays as $holiday) {
                $orgId = (int) $holiday->organization_id;
                $date = $holiday->holiday_date instanceof Carbon
                    ? $holiday->holiday_date->toDateString()
                    : (string) $holiday->holiday_date;
                $this->holidayDatesByOrg[$orgId][$date] = $holiday;
            }
        }

        $shiftIds = $employees->pluck('shift_id')->filter()->unique()->values()->all();
        if ($shiftIds !== []) {
            foreach (WorkShift::query()->whereIn('id', $shiftIds)->get() as $shift) {
                $this->shiftCache[(int) $shift->id] = $shift;
            }
        }
    }

    public function clearScheduleContext(): void
    {
        $this->holidayDatesByOrg = [];
        $this->shiftCache = [];
    }

    /**
     * Whether the employee is normally scheduled to work on this date (ignores approved leave).
     */
    public function isScheduledWorkday(Employee $employee, string $date): bool
    {
        return $this->evaluateSchedule($employee, $date)['should_work'];
    }

    /**
     * @return array{
     *   should_work: bool,
     *   is_weekend: bool,
     *   is_holiday: bool,
     *   reason: string|null
     * }
     */
    public function evaluateSchedule(Employee $employee, string $date): array
    {
        $day = Carbon::parse($date);
        $dow = (int) $day->dayOfWeek;

        $holiday = $this->resolveHoliday($employee, $date);
        $shift = $this->resolveShift($employee);

        $isWeekend = $dow === Carbon::SATURDAY || $dow === Carbon::SUNDAY;

        if ($holiday) {
            $worksHoliday = $shift?->works_public_holidays ?? false;
            if (! $worksHoliday) {
                return [
                    'should_work' => false,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => true,
                    'reason' => 'Public holiday: '.$holiday->name,
                ];
            }
        }

        if (! $this->weekdayAllowed($employee, $dow, $shift)) {
            return [
                'should_work' => false,
                'is_weekend' => $isWeekend,
                'is_holiday' => (bool) $holiday,
                'reason' => $this->weekdayOffReason($employee, $dow, $isWeekend),
            ];
        }

        return [
            'should_work' => true,
            'is_weekend' => $isWeekend,
            'is_holiday' => (bool) $holiday,
            'reason' => null,
        ];
    }

    /**
     * @return array{
     *   should_work: bool,
     *   suggested_status: string,
     *   reason: string|null,
     *   is_weekend: bool,
     *   is_holiday: bool,
     *   is_leave: bool
     * }
     */
    public function evaluate(Employee $employee, string $date): array
    {
        $day = Carbon::parse($date);
        $dow = (int) $day->dayOfWeek;

        $leave = EmployeeLeaveDay::query()
            ->where('employee_id', $employee->id)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if ($leave) {
            $isHalf = $leave->duration_type === 'half_day';
            $period = $leave->half_day_period
                ? ' ('.$leave->half_day_period.')'
                : '';

            return [
                'should_work' => $isHalf,
                'suggested_status' => $isHalf ? 'half_day' : 'leave',
                'reason' => ($isHalf ? 'Half day leave' : 'Approved leave')
                    .' — '.$leave->leave_type.$period,
                'is_weekend' => false,
                'is_holiday' => false,
                'is_leave' => true,
            ];
        }

        $holiday = $this->resolveHoliday($employee, $date);
        $shift = $this->resolveShift($employee);
        $isWeekend = $dow === Carbon::SATURDAY || $dow === Carbon::SUNDAY;

        if ($holiday) {
            $worksHoliday = $shift?->works_public_holidays ?? false;
            if (! $worksHoliday) {
                return [
                    'should_work' => false,
                    'suggested_status' => 'holiday',
                    'reason' => 'Public holiday: '.$holiday->name,
                    'is_weekend' => $isWeekend,
                    'is_holiday' => true,
                    'is_leave' => false,
                ];
            }
        }

        if (! $this->weekdayAllowed($employee, $dow, $shift)) {
            return [
                'should_work' => false,
                'suggested_status' => 'holiday',
                'reason' => $this->weekdayOffReason($employee, $dow, $isWeekend),
                'is_weekend' => $isWeekend,
                'is_holiday' => (bool) $holiday,
                'is_leave' => false,
            ];
        }

        return [
            'should_work' => true,
            'suggested_status' => 'present',
            'reason' => null,
            'is_weekend' => $isWeekend,
            'is_holiday' => (bool) $holiday,
            'is_leave' => false,
        ];
    }

    public function assertCanClockIn(Employee $employee, ?string $at = null): void
    {
        $date = ($at ? Carbon::parse($at) : now())->toDateString();
        $eval = $this->evaluate($employee, $date);

        if (! $eval['should_work']) {
            throw new \InvalidArgumentException($eval['reason'] ?? 'Not a working day for this employee.');
        }
    }

    protected function resolveHoliday(Employee $employee, string $date): ?OrganizationHoliday
    {
        $holiday = $this->holidayDatesByOrg[(int) $employee->organization_id][$date] ?? null;
        if ($holiday !== null) {
            return $holiday;
        }

        return OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->first();
    }

    protected function resolveShift(Employee $employee): ?WorkShift
    {
        if (! $employee->shift_id) {
            return null;
        }

        return $this->shiftCache[(int) $employee->shift_id] ?? WorkShift::find($employee->shift_id);
    }

    /**
     * @param  list<int>|null  $workWeekdays  Carbon dayOfWeek (0=Sun … 6=Sat)
     */
    protected function normalizedWorkWeekdays(Employee $employee): ?array
    {
        $raw = $employee->work_weekdays;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! is_array($raw)) {
            return null;
        }
        $days = array_values(array_unique(array_map('intval', $raw)));
        $days = array_values(array_filter($days, fn ($d) => $d >= 0 && $d <= 6));

        return $days === [] ? null : $days;
    }

    protected function weekdayAllowed(Employee $employee, int $dow, ?WorkShift $shift): bool
    {
        $custom = $this->normalizedWorkWeekdays($employee);
        if ($custom !== null) {
            return in_array($dow, $custom, true);
        }

        // Default: Mon–Fri always; Sat/Sun only when the shift includes them.
        if ($dow === Carbon::SATURDAY) {
            return (bool) ($shift?->works_saturday);
        }
        if ($dow === Carbon::SUNDAY) {
            return (bool) ($shift?->works_sunday);
        }

        return true;
    }

    protected function weekdayOffReason(Employee $employee, int $dow, bool $isWeekend): string
    {
        if ($this->normalizedWorkWeekdays($employee) !== null) {
            return 'Not a scheduled workday for this employee';
        }

        if ($isWeekend) {
            return 'Weekend — shift does not include this day';
        }

        return 'Not a scheduled workday for this employee';
    }
}
