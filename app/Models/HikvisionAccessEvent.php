<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HikvisionAccessEvent extends Model
{
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

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceClockDevice::class, 'attendance_clock_device_id');
    }
}
