<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\WorkShift;
use Carbon\Carbon;

class LeaveRequestCalculator
{
    /** Default workday hours when employee has no shift. */
    public const DEFAULT_SHIFT_HOURS = 8.0;

    public function __construct(
        protected AttendanceDayPolicy $dayPolicy,
    ) {}

    /**
     * @return array{
     *   total_days: float,
     *   total_hours: float,
     *   shift_hours_per_day: float,
     *   calendar_days: int,
     *   working_days: float,
     *   same_day: bool
     * }
     */
    public function calculate(
        Employee $employee,
        string $startDate,
        string $endDate,
        string $durationType = 'full_day',
        ?string $halfDayPeriod = null,
    ): array {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->startOfDay();

        if ($end->lt($start)) {
            throw new \InvalidArgumentException('End date must be on or after start date.');
        }

        $sameDay = $start->isSameDay($end);
        $calendarDays = (int) $start->diffInDays($end) + 1;
        $shiftHours = $this->shiftHoursForEmployee($employee);

        if ($durationType === 'half_day') {
            if (! $sameDay) {
                throw new \InvalidArgumentException('Half day leave applies to a single date only.');
            }
            if (! in_array($halfDayPeriod, ['morning', 'afternoon'], true)) {
                throw new \InvalidArgumentException('Select morning or afternoon for half day leave.');
            }
            if (! $this->dayPolicy->isScheduledWorkday($employee, $start->toDateString())) {
                throw new \InvalidArgumentException('Half day leave must fall on a scheduled working day.');
            }

            return [
                'total_days' => 0.5,
                'total_hours' => round($shiftHours / 2, 2),
                'shift_hours_per_day' => $shiftHours,
                'calendar_days' => 1,
                'working_days' => 0.5,
                'same_day' => true,
            ];
        }

        $workingDays = $this->countWorkingDays($employee, $start, $end);
        if ($workingDays <= 0) {
            throw new \InvalidArgumentException(
                'The selected dates include no scheduled working days for this employee.',
            );
        }

        $totalHours = round($shiftHours * $workingDays, 2);

        return [
            'total_days' => $workingDays,
            'total_hours' => $totalHours,
            'shift_hours_per_day' => $shiftHours,
            'calendar_days' => $calendarDays,
            'working_days' => $workingDays,
            'same_day' => $sameDay,
        ];
    }

    public function countWorkingDays(Employee $employee, Carbon $start, Carbon $end): float
    {
        $count = 0.0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($this->dayPolicy->isScheduledWorkday($employee, $cursor->toDateString())) {
                $count += 1;
            }
            $cursor->addDay();
        }

        return $count;
    }

    public function shiftHoursForEmployee(Employee $employee): float
    {
        if (! $employee->shift_id) {
            return self::DEFAULT_SHIFT_HOURS;
        }

        $shift = WorkShift::find($employee->shift_id);
        if (! $shift) {
            return self::DEFAULT_SHIFT_HOURS;
        }

        return $this->hoursBetweenTimes($shift->start_time, $shift->end_time, (bool) $shift->crosses_midnight);
    }

    public function hoursBetweenTimes(?string $start, ?string $end, bool $crossesMidnight = false): float
    {
        if (! $start || ! $end) {
            return self::DEFAULT_SHIFT_HOURS;
        }

        $in = $this->timeToSeconds($start);
        $out = $this->timeToSeconds($end);
        if ($crossesMidnight && $out <= $in) {
            $out += 86400;
        } elseif ($out <= $in) {
            $out += 86400;
        }

        return round(($out - $in) / 3600, 2);
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);

        return $h * 3600 + $m * 60;
    }
}
