<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeAttendance extends Model
{
    use HasFactory;

    protected $table = 'employee_attendance';
    public $timestamps = false;

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
        'notes',
        'payroll_run_id',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'hours_worked' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
