<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceClockDevice extends Model
{
    use HasFactory;

    protected $table = 'attendance_clock_devices';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'device_no',
        'location',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
