<?php

namespace Tests\Unit;

use App\Models\EmployeeDeduction;
use App\Models\PayrollDeductionType;
use PHPUnit\Framework\TestCase;

class PayrollDeductionAmountTest extends TestCase
{
    public function test_fixed_employee_deduction_is_never_scaled_by_period_gross(): void
    {
        $ded = new EmployeeDeduction([
            'calc_type' => 'fixed',
            'amount' => 5000,
            'is_active' => true,
        ]);

        $this->assertSame(5000.0, $ded->payrollDeductionAmount(30_000.0));
        $this->assertSame(5000.0, $ded->payrollDeductionAmount(100_000.0));
    }

    public function test_percentage_uses_contract_gross_not_prorated_period_gross(): void
    {
        $ded = new EmployeeDeduction([
            'calc_type' => 'percentage',
            'percentage' => 10,
            'is_active' => true,
        ]);

        $contractGross = 100_000.0;
        $proratedPeriodGross = 50_000.0;

        $this->assertSame(10_000.0, $ded->payrollDeductionAmount($contractGross));
        $this->assertNotSame(
            round($proratedPeriodGross * 0.1, 2),
            $ded->payrollDeductionAmount($contractGross),
        );
    }

    public function test_org_deduction_type_fixed_amount_is_full_per_run(): void
    {
        $type = new PayrollDeductionType([
            'calc_type' => 'fixed',
            'default_amount' => 1500,
            'is_active' => true,
        ]);

        $this->assertSame(1500.0, $type->payrollDeductionAmount(80_000.0));
        $this->assertSame(1500.0, $type->payrollDeductionAmount(0.0));
    }

    public function test_employee_deduction_falls_back_to_linked_type_default(): void
    {
        $type = new PayrollDeductionType([
            'calc_type' => 'fixed',
            'default_amount' => 1500,
            'is_active' => true,
        ]);
        $ded = new EmployeeDeduction([
            'deduction_type_id' => 1,
            'calc_type' => 'fixed',
            'amount' => 0,
            'is_active' => true,
        ]);
        $ded->setRelation('deductionType', $type);

        $this->assertSame(1500.0, $ded->payrollDeductionAmount(50_000.0));
    }
}
