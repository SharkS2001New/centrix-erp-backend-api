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
        'branch_id',
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

    public function isPartialDay(): bool
    {
        if ($this->duration_type === 'half_day') {
            return true;
        }
        if ($this->duration_type === 'hourly') {
            return (float) $this->total_days + 0.01 < 1.0;
        }

        return false;
    }

    /** Hours this leave covers on a scheduled workday it includes. */
    public function hoursOnCoveredDay(float $dayExpectedHours): float
    {
        $expected = max(0.0, $dayExpectedHours);
        if ($this->duration_type === 'hourly') {
            return round(min(max(0.0, (float) $this->total_hours), $expected), 2);
        }

        return round($expected * $this->dayFraction($expected), 2);
    }

    /** Fraction of a scheduled workday this leave covers on a date it includes. */
    public function dayFraction(?float $dayExpectedHours = null): float
    {
        return match ($this->duration_type) {
            'half_day' => 0.5,
            'hourly' => $dayExpectedHours !== null && $dayExpectedHours > 0
                ? min(1.0, max(0.0, (float) $this->total_hours / $dayExpectedHours))
                : min(1.0, max(0.0, (float) $this->total_days)),
            default => 1.0,
        };
    }

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
