<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeClockSession extends Model
{
    use HasFactory;

    protected $table = 'employee_clock_sessions';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'branch_id',
        'source',
        'clock_in_at',
        'clock_out_at',
        'device_identifier',
        'clock_in_latitude',
        'clock_in_longitude',
        'clock_in_address',
        'clock_in_photo_path',
        'clock_in_face_match_score',
        'clock_in_geofence_distance_metres',
        'clock_out_latitude',
        'clock_out_longitude',
        'clock_out_address',
        'clock_out_photo_path',
        'clock_out_face_match_score',
        'clock_out_geofence_distance_metres',
        'attendance_id',
    ];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
        'clock_in_latitude' => 'decimal:7',
        'clock_in_longitude' => 'decimal:7',
        'clock_out_latitude' => 'decimal:7',
        'clock_out_longitude' => 'decimal:7',
        'clock_in_face_match_score' => 'decimal:4',
        'clock_out_face_match_score' => 'decimal:4',
        'clock_in_geofence_distance_metres' => 'decimal:2',
        'clock_out_geofence_distance_metres' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function attendance()
    {
        return $this->belongsTo(EmployeeAttendance::class, 'attendance_id');
    }
}
