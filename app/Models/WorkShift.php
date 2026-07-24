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
        'crosses_midnight',
        'works_saturday',
        'works_sunday',
        'works_public_holidays',
        'use_alternate_hours',
        'alternate_start_time',
        'alternate_end_time',
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
        'is_active' => 'boolean',
    ];

    /**
     * Resolve start/end for a calendar day.
     * Alternate hours apply on Saturday and public holidays when enabled.
     *
     * @return array{start_time: ?string, end_time: ?string, crosses_midnight: bool}
     */
    public function hoursForDate(string $date, bool $isPublicHoliday = false): array
    {
        $dow = (int) \Carbon\Carbon::parse($date)->dayOfWeek;
        $useAlternate = (bool) $this->use_alternate_hours
            && $this->alternate_start_time
            && $this->alternate_end_time
            && (
                $isPublicHoliday
                || $dow === \Carbon\Carbon::SATURDAY
            );

        if ($useAlternate) {
            return [
                'start_time' => (string) $this->alternate_start_time,
                'end_time' => (string) $this->alternate_end_time,
                'crosses_midnight' => (bool) $this->alternate_crosses_midnight,
            ];
        }

        return [
            'start_time' => $this->start_time ? (string) $this->start_time : null,
            'end_time' => $this->end_time ? (string) $this->end_time : null,
            'crosses_midnight' => (bool) $this->crosses_midnight,
        ];
    }
}
