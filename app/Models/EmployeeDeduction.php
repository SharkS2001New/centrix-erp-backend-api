<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeDeduction extends Model
{
    use HasFactory;

    protected $table = 'employee_deductions';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'branch_id',
        'deduction_type_id',
        'name',
        'calc_type',
        'amount',
        'percentage',
        'start_date',
        'end_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'amount' => 'decimal:2',
        'percentage' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function deductionType()
    {
        return $this->belongsTo(PayrollDeductionType::class, 'deduction_type_id');
    }

    /**
     * Other deduction for one payroll run.
     * Fixed: full configured amount (never prorated for attendance or partial periods).
     * Percentage: % of contract monthly gross (basic + monthly allowances), not prorated period pay.
     */
    public function payrollDeductionAmount(float $contractGrossForPercent): float
    {
        if (! $this->is_active) {
            return 0.0;
        }
        $today = now()->toDateString();
        if ($this->start_date && $today < $this->start_date->toDateString()) {
            return 0.0;
        }
        if ($this->end_date && $today > $this->end_date->toDateString()) {
            return 0.0;
        }
        if ($this->calc_type === 'percentage') {
            $pct = (float) $this->percentage;
            if ($pct <= 0 && $this->deduction_type_id) {
                $type = $this->relationLoaded('deductionType')
                    ? $this->deductionType
                    : $this->deductionType()->first();
                if ($type?->calc_type === 'percentage') {
                    $pct = (float) $type->default_percentage;
                }
            }

            return round($contractGrossForPercent * ($pct / 100), 2);
        }

        $amount = (float) $this->amount;
        if ($amount <= 0 && $this->deduction_type_id) {
            $type = $this->relationLoaded('deductionType')
                ? $this->deductionType
                : $this->deductionType()->first();
            if ($type?->calc_type === 'fixed') {
                $amount = (float) $type->default_amount;
            }
        }

        return round($amount, 2);
    }

    /** @deprecated Use payrollDeductionAmount() */
    public function monthlyAmount(float $grossPay): float
    {
        return $this->payrollDeductionAmount($grossPay);
    }

    public static function activeTotalForEmployee(int $employeeId, float $contractGrossForPercent): float
    {
        return static::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->get()
            ->sum(fn (self $d) => $d->payrollDeductionAmount($contractGrossForPercent));
    }
}
