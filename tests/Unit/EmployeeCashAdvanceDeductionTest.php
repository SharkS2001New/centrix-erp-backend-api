<?php

namespace Tests\Unit;

use App\Models\EmployeeCashAdvance;
use PHPUnit\Framework\TestCase;

class EmployeeCashAdvanceDeductionTest extends TestCase
{
    public function test_full_next_cycle_deducts_entire_balance(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'open',
            'amount' => 2500,
            'balance' => 2500,
            'repayment_mode' => 'full_next_cycle',
        ]);

        $this->assertSame(2500.0, $advance->payrollDeductionAmount());
    }

    public function test_invalid_mode_with_small_instalment_uses_balance_not_instalment(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'open',
            'amount' => 2500,
            'balance' => 2500,
            'repayment_mode' => '',
            'repayment_amount' => 2,
        ]);

        $this->assertSame(2500.0, $advance->payrollDeductionAmount());
    }

    public function test_fixed_per_cycle_with_tiny_instalment(): void
    {
        $advance = new EmployeeCashAdvance([
            'status' => 'open',
            'amount' => 2500,
            'balance' => 2500,
            'repayment_mode' => 'fixed_per_cycle',
            'repayment_amount' => 2,
        ]);

        $this->assertSame(2.0, $advance->payrollDeductionAmount());
    }
}
