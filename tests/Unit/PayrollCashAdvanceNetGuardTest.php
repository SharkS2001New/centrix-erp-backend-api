<?php

namespace Tests\Unit;

use App\Services\Payroll\PayrollCashAdvanceNetGuard;
use PHPUnit\Framework\TestCase;

class PayrollCashAdvanceNetGuardTest extends TestCase
{
    public function test_caps_full_next_cycle_advance_to_available_net(): void
    {
        $guard = new PayrollCashAdvanceNetGuard;
        $calc = [
            'nssf' => 1000,
            'shif' => 500,
            'housing_levy' => 300,
            'paye' => 2000,
            'other_deductions' => 10000,
            'deductions' => 13800,
            'net_pay' => 0,
        ];
        $line = [
            'other_deductions' => 10000,
            'payroll_meta' => [
                'deductions_detail' => [
                    [
                        'type' => 'cash_advance',
                        'id' => 9,
                        'name' => 'Cash advance',
                        'amount' => 10000,
                        'repayment_mode' => 'full_next_cycle',
                        'balance_before' => 10000,
                    ],
                ],
            ],
        ];

        // Period gross 8000, statutory 3800 → only 4200 available for the advance.
        $result = $guard->apply($calc, $line, 8000.0);
        $detail = $result['line']['payroll_meta']['deductions_detail'][0];

        $this->assertSame(4200.0, $detail['amount']);
        $this->assertSame(10000.0, $detail['amount_scheduled']);
        $this->assertTrue($detail['capped_to_net']);
        $this->assertSame(4200.0, $result['calc']['other_deductions']);
        $this->assertSame(0.0, $result['calc']['net_pay']);
        $this->assertSame(4200.0, $result['line']['other_deductions']);
    }

    public function test_leaves_full_advance_when_net_can_absorb_it(): void
    {
        $guard = new PayrollCashAdvanceNetGuard;
        $calc = [
            'nssf' => 500,
            'shif' => 200,
            'housing_levy' => 100,
            'paye' => 700,
            'other_deductions' => 2176,
            'deductions' => 3676,
            'net_pay' => 16324,
        ];
        $line = [
            'other_deductions' => 2176,
            'payroll_meta' => [
                'deductions_detail' => [
                    [
                        'type' => 'cash_advance',
                        'id' => 3,
                        'amount' => 2176,
                        'repayment_mode' => 'full_next_cycle',
                    ],
                ],
            ],
        ];

        $result = $guard->apply($calc, $line, 20000.0);
        $detail = $result['line']['payroll_meta']['deductions_detail'][0];

        $this->assertEquals(2176.0, (float) $detail['amount']);
        $this->assertArrayNotHasKey('capped_to_net', $detail);
        $this->assertEquals(2176.0, (float) $result['calc']['other_deductions']);
    }

    public function test_non_advance_deductions_reduce_available_net_first(): void
    {
        $guard = new PayrollCashAdvanceNetGuard;
        $calc = [
            'nssf' => 0,
            'shif' => 0,
            'housing_levy' => 0,
            'paye' => 0,
            'other_deductions' => 6000,
            'deductions' => 6000,
            'net_pay' => 0,
        ];
        $line = [
            'other_deductions' => 6000,
            'payroll_meta' => [
                'deductions_detail' => [
                    ['type' => 'employee_deduction', 'id' => 1, 'amount' => 1500],
                    [
                        'type' => 'cash_advance',
                        'id' => 2,
                        'amount' => 4500,
                        'repayment_mode' => 'full_next_cycle',
                    ],
                ],
            ],
        ];

        // Gross 5000, non-advance 1500 → advance can take at most 3500.
        $result = $guard->apply($calc, $line, 5000.0);
        $detail = $result['line']['payroll_meta']['deductions_detail'];

        $this->assertSame(1500.0, (float) $detail[0]['amount']);
        $this->assertSame(3500.0, (float) $detail[1]['amount']);
        $this->assertSame(5000.0, $result['calc']['other_deductions']);
        $this->assertSame(0.0, $result['calc']['net_pay']);
    }
}
