<?php

namespace Tests\Unit;

use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\SetupProfileMenuService;
use App\Models\Organization;
use Tests\TestCase;

class SpaIndustryTest extends TestCase
{
    public function test_spa_and_kinyozi_is_an_industry_with_its_own_setup(): void
    {
        $ids = array_column(IndustryRegistry::optionsPayload(), 'id');

        $this->assertContains('spa', $ids);
        $this->assertSame('SPA & Kinyozi', IndustryRegistry::labelForIndustry('spa'));
        $this->assertSame('spa', IndustryRegistry::industryForProfile('spa_kinyozi'));
        $this->assertSame(
            ['spa'],
            app(SetupProfileMenuService::class)->applicationIdsForIndustry('spa'),
        );
        $this->assertSame(
            ['SPA / Salon / Kinyozi', 'Barbershop'],
            app(SetupProfileMenuService::class)->businessTypesForProfile('spa_kinyozi'),
        );
    }

    public function test_spa_setup_does_not_turn_on_retail_or_hotel_tills(): void
    {
        $profile = config('erp.profiles.spa_kinyozi');
        $this->assertSame(['spa'], $profile['application_ids']);

        $modules = $profile['modules'];
        $apps = app(ApplicationProvisioner::class)->applicationsFromProfileModules($modules);

        $this->assertTrue($apps['spa']);
        $this->assertFalse($apps['pos']);
        $this->assertFalse($apps['backoffice']);
        $this->assertFalse($apps['hotel_bar_pos']);
        $this->assertFalse($apps['hospitality_backoffice']);
        $this->assertFalse($modules['admin']);
        $this->assertFalse($modules['hr_payroll']);

        $fromApps = app(ApplicationProvisioner::class)->enabledModulesFromApplications(
            ['spa' => true, 'admin' => false],
            false,
        );
        $this->assertTrue($fromApps['spa'] ?? false);
        $this->assertFalse($fromApps['admin'] ?? false);
        $this->assertFalse($fromApps['hr_payroll'] ?? false);
    }

    public function test_spa_capability_includes_admin_and_staff_payroll_without_enabling_those_modules(): void
    {
        $org = new Organization([
            'deployment_profile' => 'spa_kinyozi',
            'enabled_modules' => ['spa' => true],
        ]);
        $gate = new CapabilityGate($org);

        $this->assertTrue($gate->enabled('spa'));
        $this->assertTrue($gate->enabled('admin'));
        $this->assertTrue($gate->enabled('hr_payroll'));
        $this->assertTrue($gate->enabled('hr_payroll.reports'));
        $this->assertFalse($gate->selfEnabled('admin'));
        $this->assertFalse($gate->selfEnabled('hr_payroll'));
    }
}
