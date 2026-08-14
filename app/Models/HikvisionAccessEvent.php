<?php

namespace App\Models;

use App\Support\AppTimezone;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HikvisionAccessEvent extends Model
{
    public const DUPLICATE_PUNCH = 'duplicate_punch';

    public const DUPLICATE_PUNCH_DISMISSED = 'duplicate_punch_dismissed';

    public const OUTSIDE_WINDOW = 'outside_window';

    public $timestamps = false;

    protected $table = 'hikvision_access_events';

    protected $fillable = [
        'organization_id',
        'attendance_clock_device_id',
        'event_key',
        'employee_no',
        'employee_name',
        'event_time',
        'major',
        'minor',
        'attendance_status',
        'verification_method',
        'card_no',
        'serial_no',
        'raw_payload',
        'processed_at',
        'process_error',
        'clock_session_id',
    ];

    protected $casts = [
        'event_time' => 'datetime',
        'processed_at' => 'datetime',
        'raw_payload' => 'array',
        'major' => 'integer',
        'minor' => 'integer',
    ];

    protected $appends = [
        'event_time_local',
    ];

    protected function serializeDate(DateTimeInterface $date): string
    {
        return Carbon::parse($date)
            ->timezone(AppTimezone::name())
            ->format('Y-m-d\\TH:i:sP');
    }

    public function getEventTimeLocalAttribute(): ?string
    {
        if (! $this->event_time) {
            return null;
        }

        return Carbon::parse($this->event_time)
            ->timezone(AppTimezone::name())
            ->format('Y-m-d H:i:s');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceClockDevice::class, 'attendance_clock_device_id');
    }
}
