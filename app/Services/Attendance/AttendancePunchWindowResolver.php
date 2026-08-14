<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Support\AppTimezone;
use Carbon\Carbon;

/**
 * Map a fingerprint punch to clock-in / clock-out using HR time windows.
 * Extra scans outside lunch/evening out windows are ignored (not treated as clock-out).
 */
class AttendancePunchWindowResolver
{
    public const ACTION_IN = 'in';

    public const ACTION_OUT = 'out';

    public const ACTION_IGNORE = 'ignore';

    /**
     * @return self::ACTION_IN|self::ACTION_OUT|self::ACTION_IGNORE
     */
    public function resolve(Employee $employee, Carbon $punchedAt, ?EmployeeClockSession $open): string
    {
        $at = $punchedAt->copy()->timezone(AppTimezone::name());
        $settings = HrAttendanceSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $minutes = ($at->hour * 60) + $at->minute;

        if ($this->isStaleOpenSession($open, $at)) {
            $open = null;
        }

        if ($this->hasActivityInSameHour($employee, $at)) {
            return self::ACTION_IGNORE;
        }

        if (! $this->hasPunchToday($employee, $at) && $open === null) {
            return self::ACTION_IN;
        }

        if ($open) {
            if ($this->inWindow($minutes, $settings['lunch_clock_out_from'], $settings['lunch_clock_out_to'])) {
                return self::ACTION_OUT;
            }
            if ($this->inWindow($minutes, $settings['evening_clock_out_from'], $settings['evening_clock_out_to'])) {
                return self::ACTION_OUT;
            }

            return self::ACTION_IGNORE;
        }

        if ($this->inWindow($minutes, $settings['lunch_clock_in_from'], $settings['lunch_clock_in_to'])) {
            return self::ACTION_IN;
        }
        if ($this->inWindow($minutes, $settings['morning_clock_in_from'], $settings['morning_clock_in_to'])) {
            return self::ACTION_IN;
        }

        // Missed lunch-in: still clock in so the evening punch can close the day.
        return self::ACTION_IN;
    }

    public function isStaleOpenSession(?EmployeeClockSession $open, Carbon $punchedAt): bool
    {
        if (! $open?->clock_in_at) {
            return false;
        }

        $openDay = AppTimezone::normalize($open->clock_in_at)?->toDateString();
        $punchDay = $punchedAt->copy()->timezone(AppTimezone::name())->toDateString();

        return $openDay !== null && $openDay !== $punchDay;
    }

    public function lateThreshold(Employee $employee, string $date, Carbon $shiftStart): Carbon
    {
        $settings = HrAttendanceSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $lateAfter = $settings['clock_in_late_after'] ?? '08:15';
        $threshold = Carbon::parse($date.' '.HrAttendanceSettingsResolver::normalizeClockTime($lateAfter, '08:15').':00');

        return $threshold->gt($shiftStart) ? $threshold : $shiftStart;
    }

    public function hasActivityInSameHour(Employee $employee, Carbon $at): bool
    {
        [$start, $end] = $this->hourBounds($at);

        return EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('clock_in_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNotNull('clock_out_at')
                            ->whereBetween('clock_out_at', [$start, $end]);
                    });
            })
            ->exists();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function hourBounds(Carbon $at): array
    {
        $local = $at->copy()->timezone(AppTimezone::name());

        return [$local->copy()->startOfHour(), $local->copy()->endOfHour()];
    }

    protected function hasPunchToday(Employee $employee, Carbon $at): bool
    {
        $start = AppTimezone::parseDateStart($at->toDateString());
        $end = AppTimezone::parseDateEnd($at->toDateString());

        return EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('clock_in_at', [$start, $end])
                    ->orWhere(function ($inner) use ($start, $end) {
                        $inner->whereNotNull('clock_out_at')
                            ->whereBetween('clock_out_at', [$start, $end]);
                    });
            })
            ->exists();
    }

    protected function inWindow(int $minutes, mixed $from, mixed $to): bool
    {
        $start = $this->toMinutes($from);
        $end = $this->toMinutes($to);
        if ($start === null || $end === null) {
            return false;
        }
        if ($end < $start) {
            return $minutes >= $start || $minutes <= $end;
        }

        return $minutes >= $start && $minutes <= $end;
    }

    protected function toMinutes(mixed $value): ?int
    {
        $text = HrAttendanceSettingsResolver::normalizeClockTime($value, '');
        if ($text === '' || ! preg_match('/^(\d{2}):(\d{2})$/', $text, $m)) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }
}
