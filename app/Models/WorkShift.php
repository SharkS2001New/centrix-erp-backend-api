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
        'works_public_holidays' => 'boolean',
        'use_alternate_hours' => 'boolean',
        'alternate_crosses_midnight' => 'boolean',
        'lunch_required' => 'boolean',
        'alternate_lunch_required' => 'boolean',
        'is_active' => 'boolean',
    ];

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

        return [
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $crosses,
            'lunch_minutes' => $lunchRequired ? $lunchMinutes : 0,
            'lunch_required' => $lunchRequired,
        ];
    }

    /**
     * Whether Sat/Sun/holiday lunch is explicitly configured (duration and/or required flag).
     */
    public function hasAlternateLunchOverride(): bool
    {
        return $this->alternate_lunch_minutes !== null
            || $this->alternate_lunch_required !== null;
    }
}
