<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WorkShift extends Model
{
    use HasFactory;

    protected $table = 'work_shifts';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'shift_code',
        'shift_name',
        'start_time',
        'end_time',
        'lunch_minutes',
        'lunch_required',
        'crosses_midnight',
        'works_saturday',
        'works_sunday',
        'work_weekdays',
        'works_public_holidays',
        'use_alternate_hours',
        'alternate_start_time',
        'alternate_end_time',
        'alternate_lunch_minutes',
        'alternate_lunch_required',
        'alternate_crosses_midnight',
        'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'works_saturday' => 'boolean',
        'works_sunday' => 'boolean',
        'work_weekdays' => 'array',
        'works_public_holidays' => 'boolean',
        'use_alternate_hours' => 'boolean',
        'alternate_crosses_midnight' => 'boolean',
        'lunch_required' => 'boolean',
        'alternate_lunch_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Carbon dayOfWeek ints (0=Sun … 6=Sat). Null means legacy Mon–Fri plus weekend flags.
     *
     * @return list<int>|null
     */
    public function scheduledWeekdays(): ?array
    {
        $raw = $this->work_weekdays;
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

    /**
     * Resolve start/end/lunch for a calendar day.
     * Alternate hours (and/or lunch) apply on Saturday, Sunday, and public holidays when configured.
     *
     * @return array{
     *   start_time: ?string,
     *   end_time: ?string,
     *   crosses_midnight: bool,
     *   lunch_minutes: int,
     *   lunch_required: bool
     * }
     */
    public function hoursForDate(string $date, bool $isPublicHoliday = false): array
    {
        $dow = (int) \Carbon\Carbon::parse($date)->dayOfWeek;
        $isSaturday = $dow === \Carbon\Carbon::SATURDAY;
        $isSunday = $dow === \Carbon\Carbon::SUNDAY;
        $isAlternateDay = $isPublicHoliday || $isSaturday || $isSunday;

        $useAlternateTimes = (bool) $this->use_alternate_hours
            && $this->alternate_start_time
            && $this->alternate_end_time
            && $isAlternateDay;

        if ($useAlternateTimes) {
            $start = (string) $this->alternate_start_time;
            $end = (string) $this->alternate_end_time;
            $crosses = (bool) $this->alternate_crosses_midnight;
        } else {
            $start = $this->start_time ? (string) $this->start_time : null;
            $end = $this->end_time ? (string) $this->end_time : null;
            $crosses = (bool) $this->crosses_midnight;
        }

        $weekdayLunchRequired = (bool) ($this->lunch_required ?? true);
        $weekdayLunchMinutes = max(0, (int) ($this->lunch_minutes ?? ($weekdayLunchRequired ? 60 : 0)));

        if ($isAlternateDay && $this->hasAlternateLunchOverride()) {
            $lunchRequired = $this->alternate_lunch_required !== null
                ? (bool) $this->alternate_lunch_required
                : $weekdayLunchRequired;
            $lunchMinutes = $this->alternate_lunch_minutes !== null
                ? max(0, (int) $this->alternate_lunch_minutes)
                : $weekdayLunchMinutes;
        } else {
            $lunchRequired = $weekdayLunchRequired;
            $lunchMinutes = $weekdayLunchMinutes;
        }

        $lunchRequired = $lunchRequired && $lunchMinutes > 0;

        // Short Sat/Sun/holiday hours (e.g. 08:00–12:00) are a full day for that
        // roster. Do not inherit weekday lunch unless weekend lunch is set explicitly.
        if ($useAlternateTimes && ! $this->hasAlternateLunchOverride() && $lunchRequired) {
            $startMin = $this->clockToMinutes($start);
            $endMin = $this->clockToMinutes($end);
            if ($startMin !== null && $endMin !== null) {
                $span = $crosses ? ((24 * 60) - $startMin + $endMin) : max(0, $endMin - $startMin);
                if ($span > 0 && $span < (6 * 60)) {
                    $lunchRequired = false;
                    $lunchMinutes = 0;
                }
            }
        }

        return [
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $crosses,
            'lunch_minutes' => $lunchRequired ? $lunchMinutes : 0,
            'lunch_required' => $lunchRequired,
        ];
    }

    protected function clockToMinutes(?string $time): ?int
    {
        if ($time === null || $time === '') {
            return null;
        }
        if (! preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }

        return ((int) $m[1] * 60) + (int) $m[2];
    }

    /**
     * Whether Sat/Sun/holiday lunch is explicitly configured (duration and/or required flag).
     */
    public function hasAlternateLunchOverride(): bool
    {
        return $this->alternate_lunch_minutes !== null
            || $this->alternate_lunch_required !== null;
    }

    /**
     * Human-readable roster for Centrix AI (weekday + Saturday/Sunday alternate hours).
     *
     * @return array<string, mixed>
     */
    public function presentForAi(): array
    {
        $weekdayLabels = $this->weekdayLabels();
        $weekdayHours = $this->formatHoursBlock(
            $this->start_time,
            $this->end_time,
            (int) ($this->lunch_minutes ?? 0),
            (bool) ($this->lunch_required ?? false),
            (bool) ($this->crosses_midnight ?? false),
        );

        $usesAlternate = (bool) ($this->use_alternate_hours ?? false)
            && $this->alternate_start_time
            && $this->alternate_end_time;

        $alternateHours = $usesAlternate
            ? $this->formatHoursBlock(
                $this->alternate_start_time,
                $this->alternate_end_time,
                (int) ($this->alternate_lunch_minutes ?? 0),
                $this->alternate_lunch_required !== null
                    ? (bool) $this->alternate_lunch_required
                    : (bool) ($this->lunch_required ?? false),
                (bool) ($this->alternate_crosses_midnight ?? false),
            )
            : null;

        $worksSaturday = $this->worksOnWeekday(\Carbon\Carbon::SATURDAY);
        $worksSunday = $this->worksOnWeekday(\Carbon\Carbon::SUNDAY);

        $byDay = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            if (! $this->worksOnWeekday($dow)) {
                continue;
            }
            $isWeekend = $dow === \Carbon\Carbon::SATURDAY || $dow === \Carbon\Carbon::SUNDAY;
            $hours = ($usesAlternate && $isWeekend) ? $alternateHours : $weekdayHours;
            $byDay[] = [
                'day' => $this->dayName($dow),
                'day_of_week' => $dow,
                'start_time' => $hours['start_time'] ?? null,
                'end_time' => $hours['end_time'] ?? null,
                'lunch_minutes' => $hours['lunch_minutes'] ?? 0,
                'label' => $hours['label'] ?? null,
                'is_alternate_hours' => (bool) ($usesAlternate && $isWeekend),
                'note' => ($usesAlternate && $isWeekend)
                    ? 'Scheduled shorter weekend shift — this is a full roster day for this shift, not a half-day absence.'
                    : null,
            ];
        }

        return [
            'name' => (string) ($this->shift_name ?? ''),
            'code' => $this->shift_code ? (string) $this->shift_code : null,
            'weekday_days' => $weekdayLabels,
            'weekday_hours' => $weekdayHours,
            'works_saturday' => $worksSaturday,
            'works_sunday' => $worksSunday,
            'works_public_holidays' => (bool) ($this->works_public_holidays ?? false),
            'use_alternate_hours' => $usesAlternate,
            'saturday_sunday_holiday_hours' => $usesAlternate ? $alternateHours : null,
            'schedule_by_day' => $byDay,
            'answer_tip' => $usesAlternate
                ? 'When attendance on Saturday/Sunday is shorter than Mon–Fri, that matches alternate weekend hours on this shift. '
                    .'Do NOT call those days "half-days" — they are fully scheduled weekend days. Quote schedule_by_day times.'
                : 'Quote schedule_by_day / weekday_hours. Only call a day a half-day if attendance hours are below that day\'s scheduled span.',
        ];
    }

    public function worksOnWeekday(int $dayOfWeek): bool
    {
        $days = $this->scheduledWeekdays();
        if ($days !== null) {
            return in_array($dayOfWeek, $days, true);
        }

        if ($dayOfWeek === \Carbon\Carbon::SATURDAY) {
            return (bool) ($this->works_saturday ?? false);
        }
        if ($dayOfWeek === \Carbon\Carbon::SUNDAY) {
            return (bool) ($this->works_sunday ?? false);
        }

        // Legacy: Mon–Fri when work_weekdays is null.
        return $dayOfWeek >= \Carbon\Carbon::MONDAY && $dayOfWeek <= \Carbon\Carbon::FRIDAY;
    }

    /**
     * @return list<string>
     */
    protected function weekdayLabels(): array
    {
        $labels = [];
        foreach ([1, 2, 3, 4, 5, 6, 0] as $dow) {
            if ($this->worksOnWeekday($dow)) {
                $labels[] = $this->dayName($dow);
            }
        }

        return $labels;
    }

    protected function dayName(int $dayOfWeek): string
    {
        return match ($dayOfWeek) {
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Day '.$dayOfWeek,
        };
    }

    /**
     * @return array{start_time: ?string, end_time: ?string, lunch_minutes: int, lunch_required: bool, crosses_midnight: bool, label: string}
     */
    protected function formatHoursBlock(
        mixed $start,
        mixed $end,
        int $lunchMinutes,
        bool $lunchRequired,
        bool $crossesMidnight,
    ): array {
        $startClock = $this->formatClockValue($start);
        $endClock = $this->formatClockValue($end);
        $lunch = $lunchRequired ? max(0, $lunchMinutes) : 0;
        $parts = [];
        if ($startClock && $endClock) {
            $parts[] = "{$startClock}–{$endClock}";
        }
        if ($lunch > 0) {
            $parts[] = "{$lunch}-min lunch";
        }
        if ($crossesMidnight) {
            $parts[] = 'crosses midnight';
        }

        return [
            'start_time' => $startClock,
            'end_time' => $endClock,
            'lunch_minutes' => $lunch,
            'lunch_required' => $lunch > 0,
            'crosses_midnight' => $crossesMidnight,
            'label' => $parts !== [] ? implode(', ', $parts) : 'Hours not set',
        ];
    }

    protected function formatClockValue(mixed $value): ?string
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
}
