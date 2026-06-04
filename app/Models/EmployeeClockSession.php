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
        'clock_in_at',
        'clock_out_at',
        'device_identifier',
        'attendance_id',
    ];

    protected $casts = [
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
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
