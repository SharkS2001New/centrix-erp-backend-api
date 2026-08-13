<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HikvisionEmployeeMapping extends Model
{
    protected $table = 'hikvision_employee_mappings';

    protected $fillable = [
        'organization_id',
        'attendance_clock_device_id',
        'employee_id',
        'hikvision_employee_no',
        'sync_status',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(AttendanceClockDevice::class, 'attendance_clock_device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
