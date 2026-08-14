<?php

namespace App\Services\Attendance;

use App\Models\Employee;
use App\Models\EmployeeClockSession;
use App\Models\OrganizationHoliday;
use App\Support\AppTimezone;
use Carbon\Carbon;

/**
 * Map a fingerprint punch to clock-in / clock-out using the employee's HR shift
 * (start, end, lunch). Org punch windows are the fallback when no shift is assigned.
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
        $windows = $this->windowsFor($employee, $at);
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
            if ($this->inWindow($minutes, $windows['lunch_clock_out_from'], $windows['lunch_clock_out_to'])) {
                return self::ACTION_OUT;
            }
            if ($this->inWindow($minutes, $windows['evening_clock_out_from'], $windows['evening_clock_out_to'])) {
                return self::ACTION_OUT;
            }

            return self::ACTION_IGNORE;
        }

        if ($this->inWindow($minutes, $windows['lunch_clock_in_from'], $windows['lunch_clock_in_to'])) {
            return self::ACTION_IN;
        }
        if ($this->inWindow($minutes, $windows['morning_clock_in_from'], $windows['morning_clock_in_to'])) {
            return self::ACTION_IN;
        }

        // Missed lunch-in: still clock in so the evening punch can close the day.
        return self::ACTION_IN;
    }

    /**
     * Punch classification windows for this employee on this instant (Africa/Nairobi).
     *
     * @return array{
     *   morning_clock_in_from: string,
     *   morning_clock_in_to: string,
     *   lunch_clock_out_from: string,
     *   lunch_clock_out_to: string,
     *   lunch_clock_in_from: string,
     *   lunch_clock_in_to: string,
     *   evening_clock_out_from: string,
     *   evening_clock_out_to: string,
     *   clock_in_late_after: string,
     *   source: string
     * }
     */
    public function windowsFor(Employee $employee, Carbon $at): array
    {
        $settings = HrAttendanceSettingsResolver::forOrganizationId((int) $employee->organization_id);
        $date = $at->copy()->timezone(AppTimezone::name())->toDateString();
        $hours = $this->shiftHoursForDate($employee, $date);
        if ($hours === null) {
            return $this->orgWindows($settings);
        }

        return $this->windowsFromShiftHours($hours, $settings);
    }

    public function isStaleOpenSession(?EmployeeClockSession $open, Carbon $punchedAt): bool
    {
        if (! $open?->clock_in_at) {
            return false;
        }

        $openDay = $this->wallCalendarDate($open->clock_in_at);
        $punchDay = $this->wallCalendarDate($punchedAt);

        return $openDay !== null && $punchDay !== null && $openDay !== $punchDay;
    }

    public function lateThreshold(Employee $employee, string $date, Carbon $shiftStart): Carbon
    {
        $at = Carbon::parse($date.' 12:00:00', AppTimezone::name());
        $windows = $this->windowsFor($employee, $at);
        $lateAfter = $windows['clock_in_late_after'] ?: '08:15';
        $threshold = Carbon::parse(
            $date.' '.HrAttendanceSettingsResolver::normalizeClockTime($lateAfter, '08:15').':00',
            AppTimezone::name(),
        );

        if ($windows['source'] === 'shift') {
            return $threshold;
        }

        return $threshold->gt($shiftStart) ? $threshold : $shiftStart;
    }

    public function hasActivityInSameHour(Employee $employee, Carbon $at): bool
    {
        $local = $at->copy()->timezone(AppTimezone::name());
        $hourKey = $local->format('Y-m-d H');
        $dayStart = $local->copy()->startOfDay()->format('Y-m-d H:i:s');
        $dayEnd = $local->copy()->endOfDay()->format('Y-m-d H:i:s');

        $sessions = EmployeeClockSession::query()
            ->where('employee_id', $employee->id)
            ->where(function ($q) use ($dayStart, $dayEnd) {
                $q->whereBetween('clock_in_at', [$dayStart, $dayEnd])
                    ->orWhere(function ($inner) use ($dayStart, $dayEnd) {
                        $inner->whereNotNull('clock_out_at')
                            ->whereBetween('clock_out_at', [$dayStart, $dayEnd]);
                    });
            })
            ->get(['clock_in_at', 'clock_out_at']);

        foreach ($sessions as $session) {
            foreach ([$session->clock_in_at, $session->clock_out_at] as $stamp) {
                if ($stamp && $this->wallHourKey($stamp) === $hourKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    public function hourBounds(Carbon $at): array
    {
        $local = $at->copy()->timezone(AppTimezone::name());

        return [$local->copy()->startOfHour(), $local->copy()->endOfHour()];
    }

    protected function wallCalendarDate(mixed $value): ?string
    {
        $wall = AppTimezone::fromDeviceWallClock($value) ?? AppTimezone::normalize($value);

        return $wall?->format('Y-m-d');
    }

    protected function wallHourKey(mixed $value): ?string
    {
        $wall = AppTimezone::fromDeviceWallClock($value) ?? AppTimezone::normalize($value);

        return $wall?->format('Y-m-d H');
    }

    /**
     * @param  array{
     *   start_time: ?string,
     *   end_time: ?string,
     *   crosses_midnight: bool,
     *   lunch_minutes: int,
     *   lunch_required: bool
     * }  $hours
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    protected function windowsFromShiftHours(array $hours, array $settings): array
    {
        $start = $this->toMinutes($hours['start_time'] ?? null);
        $end = $this->toMinutes($hours['end_time'] ?? null);
        if ($start === null || $end === null) {
            return $this->orgWindows($settings);
        }

        $crosses = (bool) ($hours['crosses_midnight'] ?? false);
        $span = $crosses ? ((24 * 60) - $start + $end) : max(0, $end - $start);
        $lunchMin = ((bool) ($hours['lunch_required'] ?? false))
            ? max(0, (int) ($hours['lunch_minutes'] ?? 0))
            : 0;
        $grace = $this->orgLateGraceMinutes($settings);

        $lunchOutFrom = '';
        $lunchOutTo = '';
        $lunchInFrom = '';
        $lunchInTo = '';
        if ($lunchMin > 0 && $span > $lunchMin) {
            $lunchStart = $this->wrapMinutes($start + (int) floor(($span - $lunchMin) / 2));
            $lunchEnd = $this->wrapMinutes($lunchStart + $lunchMin);
            $lunchOutFrom = $this->formatMinutes($this->wrapMinutes($lunchStart - 30));
            $lunchOutTo = $this->formatMinutes($lunchEnd);
            $lunchInFrom = $this->formatMinutes($lunchStart);
            $lunchInTo = $this->formatMinutes($this->wrapMinutes($lunchEnd + 120));
        }

        return [
            'morning_clock_in_from' => $this->formatMinutes($this->wrapMinutes($start - 60)),
            'morning_clock_in_to' => $this->formatMinutes($this->wrapMinutes($start + 120)),
            'lunch_clock_out_from' => $lunchOutFrom,
            'lunch_clock_out_to' => $lunchOutTo,
            'lunch_clock_in_from' => $lunchInFrom,
            'lunch_clock_in_to' => $lunchInTo,
            'evening_clock_out_from' => $this->formatMinutes($this->wrapMinutes($end - 60)),
            'evening_clock_out_to' => $this->formatMinutes($this->wrapMinutes($end + 300)),
            'clock_in_late_after' => $this->formatMinutes($this->wrapMinutes($start + $grace)),
            'source' => 'shift',
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    protected function orgWindows(array $settings): array
    {
        return [
            'morning_clock_in_from' => (string) ($settings['morning_clock_in_from'] ?? '08:00'),
            'morning_clock_in_to' => (string) ($settings['morning_clock_in_to'] ?? '10:00'),
            'lunch_clock_out_from' => (string) ($settings['lunch_clock_out_from'] ?? '12:30'),
            'lunch_clock_out_to' => (string) ($settings['lunch_clock_out_to'] ?? '14:00'),
            'lunch_clock_in_from' => (string) ($settings['lunch_clock_in_from'] ?? '13:00'),
            'lunch_clock_in_to' => (string) ($settings['lunch_clock_in_to'] ?? '16:00'),
            'evening_clock_out_from' => (string) ($settings['evening_clock_out_from'] ?? '16:00'),
            'evening_clock_out_to' => (string) ($settings['evening_clock_out_to'] ?? '22:00'),
            'clock_in_late_after' => (string) ($settings['clock_in_late_after'] ?? '08:15'),
            'source' => 'organization',
        ];
    }

    /**
     * Minutes after 08:00 implied by Admin → late after (default 08:15 → 15).
     *
     * @param  array<string, mixed>  $settings
     */
    protected function orgLateGraceMinutes(array $settings): int
    {
        $late = $this->toMinutes($settings['clock_in_late_after'] ?? '08:15') ?? (8 * 60 + 15);

        return max(0, min(180, $late - (8 * 60)));
    }

    /**
     * @return array{
     *   start_time: ?string,
     *   end_time: ?string,
     *   crosses_midnight: bool,
     *   lunch_minutes: int,
     *   lunch_required: bool
     * }|null
     */
    protected function shiftHoursForDate(Employee $employee, string $date): ?array
    {
        $employee->loadMissing('shift');
        $shift = $employee->shift;
        if (! $shift) {
            return null;
        }

        $isHoliday = OrganizationHoliday::query()
            ->where('organization_id', $employee->organization_id)
            ->where('is_active', true)
            ->whereDate('holiday_date', $date)
            ->exists();

        $hours = $shift->hoursForDate($date, $isHoliday);
        if (empty($hours['start_time']) || empty($hours['end_time'])) {
            return null;
        }

        return $hours;
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

    protected function wrapMinutes(int $minutes): int
    {
        $mod = $minutes % (24 * 60);
        if ($mod < 0) {
            $mod += 24 * 60;
        }

        return $mod;
    }

    protected function formatMinutes(int $minutes): string
    {
        $minutes = $this->wrapMinutes($minutes);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
