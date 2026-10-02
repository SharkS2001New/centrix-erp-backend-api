<?php

namespace Tests\Unit;

use App\Models\EmployeeCashAdvance;
use PHPUnit\Framework\TestCase;

class EmployeeCashAdvancePayrollDeductionTest extends TestCase
{
    public function test_tiny_residual_balance_is_not_deducted_on_payroll(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'open',
            'amount' => 3500,
            'balance' => 0.01,
            'repayment_mode' => 'full_next_cycle',
            'notes' => 'personal',
        ]);

        $this->assertSame(0.0, $advance->payrollDeductionAmount());
    }

    public function test_open_balance_above_epsilon_is_deducted_in_full(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'open',
            'amount' => 3000,
            'balance' => 3000,
            'repayment_mode' => 'full_next_cycle',
        ]);

        $this->assertSame(3000.0, $advance->payrollDeductionAmount());
    }

    public function test_pending_advance_is_not_deducted(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'pending',
            'amount' => 500,
            'balance' => 500,
            'repayment_mode' => 'full_next_cycle',
        ]);

        $this->assertSame(0.0, $advance->payrollDeductionAmount());
    }
}
