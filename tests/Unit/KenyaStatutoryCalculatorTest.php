<?php

namespace Tests\Unit;

use App\Services\Payroll\KenyaStatutoryCalculator;
use Tests\TestCase;

class KenyaStatutoryCalculatorTest extends TestCase
{
    protected KenyaStatutoryCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = $this->app->make(KenyaStatutoryCalculator::class);
    }

    public function test_fifty_thousand_gross_matches_2026_structure(): void
    {
        $r = $this->calculator->calculateMonthly(50000);

        $this->assertEquals(50000, $r['gross_pay']);
        $this->assertEquals(540, $r['nssf_tier1']);
        $this->assertEquals(2460, $r['nssf_tier2']);
        $this->assertEquals(3000, $r['nssf']);
        $this->assertEquals(1375, $r['shif']);
        $this->assertEquals(750, $r['housing_levy']);
        $this->assertEquals(44875, $r['taxable_income']);
        $this->assertEqualsWithDelta(4470.85, $r['paye'], 0.02);
        $this->assertLessThan($r['gross_pay'], $r['net_pay']);
        $this->assertEquals(
            round($r['nssf'] + $r['shif'] + $r['housing_levy'] + $r['paye'] + $r['other_deductions'], 2),
            $r['deductions'],
        );
    }

    public function test_zero_gross(): void
    {
        $r = $this->calculator->calculateMonthly(0);
        $this->assertEquals(0, $r['net_pay']);
        $this->assertEquals(300, $r['shif']);
    }
}

