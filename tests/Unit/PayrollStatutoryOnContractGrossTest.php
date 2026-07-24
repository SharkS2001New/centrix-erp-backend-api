<?php

namespace Tests\Unit;

use App\Services\Payroll\KenyaStatutoryCalculator;
use Tests\TestCase;

class PayrollStatutoryOnContractGrossTest extends TestCase
{
    public function test_paye_uses_contract_gross_not_prorated_period_gross(): void
    {
        $calc = $this->app->make(KenyaStatutoryCalculator::class);

        $contractGross = 30000.0;
        $periodGross = round(30000 * (20 / 22), 2); // ~27,272.73 for 20 of 22 days

        $onContract = $calc->calculateMonthly($contractGross);
        $onPeriod = $calc->calculateMonthly($periodGross);

        $this->assertGreaterThan(0, $onContract['paye']);
        $this->assertEqualsWithDelta(731.25, $onContract['paye'], 0.02);

        // Period-prorated base would understate PAYE vs the employee's set 30k gross.
        $this->assertLessThan($onContract['paye'], $onPeriod['paye']);

        // Net is period earnings minus statutory computed on contract gross.
        $statutory = round(
            $onContract['nssf'] + $onContract['shif'] + $onContract['housing_levy'] + $onContract['paye'],
            2,
        );
        $net = round(max(0, $periodGross - $statutory), 2);
        $this->assertEqualsWithDelta($periodGross - $statutory, $net, 0.02);
        $this->assertGreaterThan(0, $net);
    }
}
