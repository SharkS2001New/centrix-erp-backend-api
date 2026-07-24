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
        // Gross tax 8,245.85 − personal relief 2,400 (SHIF is not insurance relief).
        $this->assertEqualsWithDelta(5845.85, $r['paye'], 0.02);
        $this->assertEquals(0, $r['insurance_relief']);
        $this->assertLessThan($r['gross_pay'], $r['net_pay']);
        $this->assertEquals(
            round($r['nssf'] + $r['shif'] + $r['housing_levy'] + $r['paye'] + $r['other_deductions'], 2),
            $r['deductions'],
        );
    }

    public function test_thirty_thousand_gross_has_non_zero_paye(): void
    {
        $r = $this->calculator->calculateMonthly(30000);

        $this->assertEquals(26925, $r['taxable_income']);
        $this->assertGreaterThan(0, $r['paye']);
        $this->assertEqualsWithDelta(731.25, $r['paye'], 0.02);
    }

    public function test_private_insurance_relief_is_fifteen_percent_capped(): void
    {
        $r = $this->calculator->calculateMonthly(50000, 0, 10000);

        $this->assertEquals(1500, $r['insurance_relief']);
        $this->assertEqualsWithDelta(4345.85, $r['paye'], 0.02);

        $capped = $this->calculator->calculateMonthly(50000, 0, 100000);
        $this->assertEquals(5000, $capped['insurance_relief']);
    }

    public function test_zero_gross(): void
    {
        $r = $this->calculator->calculateMonthly(0);
        $this->assertEquals(0, $r['net_pay']);
        $this->assertEquals(300, $r['shif']);
    }
}
