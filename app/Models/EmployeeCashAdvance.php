<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class EmployeeCashAdvance extends Model
{
    use HasFactory;

    protected $table = 'employee_cash_advances';
    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'organization_id',
        'branch_id',
        'advance_date',
        'amount',
        'balance',
        'status',
        'repayment_mode',
        'repayment_amount',
        'notes',
    ];

    protected $casts = [
        'advance_date' => 'date',
        'amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'repayment_amount' => 'decimal:2',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function normalizedRepaymentMode(): string
    {
        $mode = trim((string) ($this->repayment_mode ?? ''));

        return in_array($mode, ['full_next_cycle', 'fixed_per_cycle'], true)
            ? $mode
            : 'full_next_cycle';
    }

    /** Amount to deduct on the next payroll run (full balance unless fixed instalment is set). */
    public function payrollDeductionAmount(): float
    {
        if ($this->status !== 'open') {
            return 0.0;
        }

        $balance = round((float) $this->balance, 2);
        $advanced = round((float) $this->amount, 2);

        if ($balance <= 0) {
            if ($advanced > 0) {
                return $advanced;
            }

            return 0.0;
        }

        $mode = $this->normalizedRepaymentMode();

        if ($mode === 'full_next_cycle') {
            if ($advanced > 0 && $balance < $advanced) {
                return $advanced;
            }

            return $balance;
        }

        $fixed = round((float) ($this->repayment_amount ?? 0), 2);
        if ($fixed <= 0) {
            return $balance;
        }

        return min($fixed, $balance);
    }
}
