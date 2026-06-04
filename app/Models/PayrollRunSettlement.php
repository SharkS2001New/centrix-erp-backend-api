<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRunSettlement extends Model
{
    public const TYPE_ATTENDANCE = 'attendance';

    public const TYPE_OVERTIME = 'overtime';

    public const TYPE_CASH_ADVANCE = 'cash_advance';

    public const TYPE_EMPLOYEE_DEDUCTION = 'employee_deduction';

    public const TYPE_LEAVE_DAY = 'leave_day';

    public $timestamps = false;

    protected $fillable = [
        'payroll_run_id',
        'organization_id',
        'item_type',
        'item_id',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function payrollRun()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }
}
