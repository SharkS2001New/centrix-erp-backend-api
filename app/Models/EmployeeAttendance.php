<?php

namespace App\Models;

use App\Support\AttendanceSourceLabels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeAttendance extends Model
{
    use HasFactory;

    protected $table = 'employee_attendance';
    public $timestamps = false;

    protected $appends = ['source_label', 'login_channel', 'login_channel_label'];

    protected $fillable = [
        'employee_id',
        'organization_id',
        'branch_id',
        'attendance_date',
        'check_in',
        'check_out',
        'status',
        'source',
        'device_identifier',
        'hours_worked',
        'expected_hours',
        'late_minutes',
        'lateness_waived',
        'lateness_waiver_reason',
        'lateness_waived_by',
        'lateness_waived_at',
        'lunch_status',
        'lunch_minutes',
        'early_leave_minutes',
        'overtime_minutes',
        'notes',
        'payroll_run_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'hours_worked' => 'decimal:2',
        'expected_hours' => 'decimal:2',
        'lateness_waived' => 'boolean',
        'lateness_waived_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function getSourceLabelAttribute(): string
    {
        return AttendanceSourceLabels::label($this->source);
    }

    public function getLoginChannelAttribute(): string
    {
        return AttendanceSourceLabels::channel($this->source);
    }

    public function getLoginChannelLabelAttribute(): string
    {
        return AttendanceSourceLabels::channelLabel($this->source);
    }
}
