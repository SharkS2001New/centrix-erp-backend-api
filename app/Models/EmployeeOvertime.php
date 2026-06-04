<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeOvertime extends Model
{
    use HasFactory;

    protected $table = 'employee_overtime';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'work_date',
        'hours',
        'rate_mode',
        'hourly_rate',
        'rate_multiplier',
        'amount',
        'status',
        'pay_period_id',
        'payroll_run_id',
        'notes',
    ];

    protected $casts = [
        'work_date' => 'date',
        'hours' => 'decimal:2',
        'hourly_rate' => 'decimal:2',
        'rate_multiplier' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function payPeriod()
    {
        return $this->belongsTo(PayPeriod::class, 'pay_period_id');
    }
}
