<?php

namespace Tests\Unit;

use App\Services\Payroll\KenyaPayrollSettingsResolver;
use App\Services\Payroll\KenyaStatutoryCalculator;
use Tests\TestCase;

class KenyaPayrollSettingsResolverTest extends TestCase
{
    public function test_defaults_match_config_file(): void
    {
        $defaults = KenyaPayrollSettingsResolver::defaults();
        $cfg = config('kenya_payroll');

        $this->assertEquals($cfg['paye']['personal_relief_monthly'], $defaults['paye']['personal_relief_monthly']);
        $this->assertEquals(count($cfg['paye']['bands']), count($defaults['paye']['bands']));
        $this->assertEquals($cfg['nssf']['rate'], $defaults['nssf']['rate']);
    }

    public function test_describe_includes_approx_tax_free_taxable(): void
    {
        $describe = KenyaPayrollSettingsResolver::describe();
        $this->assertArrayHasKey('approx_tax_free_taxable_income', $describe['effective']);
        $this->assertEqualsWithDelta(24000, $describe['effective']['approx_tax_free_taxable_income'], 0.01);
    }

    public function test_normalize_forces_last_band_open_ended(): void
    {
        $normalized = KenyaPayrollSettingsResolver::normalize([
            'paye' => [
                'personal_relief_monthly' => 2400,
                'bands' => [
                    ['up_to' => 24000, 'rate' => 0.1],
                    ['up_to' => 999999, 'rate' => 0.35],
                ],
            ],
        ]);

        $this->assertNull($normalized['paye']['bands'][1]['up_to']);
        $this->assertEquals(0.35, $normalized['paye']['bands'][1]['rate']);
    }

    public function test_platform_override_changes_calculator_paye(): void
    {
        $org = KenyaPayrollSettingsResolver::platformOrganization();
        if ($org === null) {
            $this->markTestSkipped('PLATFORM organization not seeded.');
        }

        $before = KenyaPayrollSettingsResolver::forPlatform();
        try {
            KenyaPayrollSettingsResolver::save([
                'paye' => [
                    'personal_relief_monthly' => 10000,
                ],
            ]);

            $calc = $this->app->make(KenyaStatutoryCalculator::class);
            $r = $calc->calculateMonthly(50000);
            // High relief should wipe most/all PAYE at 50k vs default ~5845.
            $this->assertLessThan(1000, $r['paye']);
            $this->assertEquals(10000, $r['personal_relief']);
        } finally {
            KenyaPayrollSettingsResolver::save($before);
        }
    }
}
