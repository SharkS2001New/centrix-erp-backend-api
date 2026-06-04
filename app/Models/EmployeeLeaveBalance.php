<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeLeaveBalance extends Model
{
    protected $table = 'employee_leave_balances';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'off_days_allocated',
        'annual_adjustment',
        'sick_adjustment',
        'notes',
    ];

    protected $casts = [
        'off_days_allocated' => 'decimal:2',
        'annual_adjustment' => 'decimal:2',
        'sick_adjustment' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public static function forEmployee(Employee $employee): self
    {
        return self::firstOrCreate(
            ['employee_id' => $employee->id],
            ['organization_id' => $employee->organization_id],
        );
    }
}
