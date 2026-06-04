<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeLeaveDay;
use App\Models\OrganizationHoliday;
use App\Models\WorkShift;
use Carbon\Carbon;

class AttendanceDayPolicy
{
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

        $holiday = OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->first();

        $shift = $employee->shift_id
            ? WorkShift::find($employee->shift_id)
            : null;

        $isWeekend = $dow === Carbon::SATURDAY || $dow === Carbon::SUNDAY;
        $worksWeekend = $shift
            ? ($dow === Carbon::SATURDAY ? $shift->works_saturday : ($dow === Carbon::SUNDAY ? $shift->works_sunday : true))
            : false;

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

        if ($isWeekend && ! $worksWeekend) {
            return [
                'should_work' => false,
                'is_weekend' => true,
                'is_holiday' => false,
                'reason' => 'Weekend — shift does not include this day',
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
        $dow = (int) $day->dayOfWeek; // 0=Sun, 6=Sat

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

        $holiday = OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->first();

        $shift = $employee->shift_id
            ? WorkShift::find($employee->shift_id)
            : null;

        $isWeekend = $dow === Carbon::SATURDAY || $dow === Carbon::SUNDAY;
        $worksWeekend = $shift
            ? ($dow === Carbon::SATURDAY ? $shift->works_saturday : ($dow === Carbon::SUNDAY ? $shift->works_sunday : true))
            : false;

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

        if ($isWeekend && ! $worksWeekend) {
            return [
                'should_work' => false,
                'suggested_status' => 'holiday',
                'reason' => 'Weekend — shift does not include this day',
                'is_weekend' => true,
                'is_holiday' => false,
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
}
