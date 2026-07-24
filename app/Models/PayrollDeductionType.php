<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PayrollDeductionType extends Model
{
    use HasFactory;

    protected $table = 'payroll_deduction_types';
    public $timestamps = false;

    protected $fillable = [
        'organization_id',
        'deduction_code',
        'name',
        'calc_type',
        'default_amount',
        'default_percentage',
        'is_active',
        'applies_to_all',
        'frequency',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'applies_to_all' => 'boolean',
        'default_amount' => 'decimal:2',
        'default_percentage' => 'decimal:2',
    ];

    public const FREQUENCY_PER_CYCLE = 'per_cycle';

    public const FREQUENCY_ONE_TIME = 'one_time';

    public function isOneTime(): bool
    {
        return ($this->frequency ?? self::FREQUENCY_PER_CYCLE) === self::FREQUENCY_ONE_TIME;
    }

    /**
     * Org-wide other deduction for one payroll run (same rules as EmployeeDeduction).
     */
    public function payrollDeductionAmount(float $contractGrossForPercent): float
    {
        if (! $this->is_active) {
            return 0.0;
        }
        if ($this->calc_type === 'percentage') {
            return round($contractGrossForPercent * ((float) $this->default_percentage / 100), 2);
        }

        return round((float) $this->default_amount, 2);
    }

    /** @deprecated Use payrollDeductionAmount() */
    public function monthlyAmount(float $grossPay): float
    {
        return $this->payrollDeductionAmount($grossPay);
    }
}
