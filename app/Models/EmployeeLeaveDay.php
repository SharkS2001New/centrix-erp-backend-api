<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeLeaveDay extends Model
{
    use HasFactory;

    protected $table = 'employee_leave_days';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'start_date',
        'end_date',
        'leave_type',
        'assignment_kind',
        'deduct_from',
        'duration_type',
        'half_day_period',
        'total_days',
        'total_hours',
        'days_deducted',
        'notes',
        'payroll_run_id',
        'approval_status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'total_days' => 'decimal:2',
        'total_hours' => 'decimal:2',
        'days_deducted' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function coversDate(string $date): bool
    {
        $d = $this->start_date->format('Y-m-d');
        $end = $this->end_date->format('Y-m-d');

        return $date >= $d && $date <= $end;
    }
}
