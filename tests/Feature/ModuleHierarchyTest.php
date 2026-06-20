<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleRegistry;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ModuleHierarchyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_enabling_domain_enables_all_children(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => true,
        ]);

        $this->assertTrue($modules['sales']);
        $this->assertTrue($modules['sales.backend']);
        $this->assertTrue($modules['sales.reports']);
        $this->assertTrue($modules['sales.pos']);
        $this->assertTrue($modules['sales.mobile']);
    }

    public function test_sales_pos_can_be_disabled_while_backoffice_sales_stay_on(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.pos' => false,
            'sales.backend' => true,
            'sales.reports' => true,
        ]);

        $this->assertTrue($modules['sales']);
        $this->assertFalse($modules['sales.pos']);
        $this->assertTrue($modules['sales.backend']);
        $this->assertTrue($modules['sales.reports']);
    }

    public function test_disabling_domain_disables_all_children_even_when_children_sent_on(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = [
            'sales' => false,
            'sales.pos' => true,
            'sales.reports' => true,
        ];
        $org->save();

        $gate = app(CapabilityGate::class)->forOrganization($org->fresh());

        $this->assertFalse($gate->enabled('sales'));
        $this->assertFalse($gate->enabled('sales.pos'));
        $this->assertFalse($gate->enabled('sales.reports'));
    }

    public function test_domain_toggle_is_all_or_nothing_per_org(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $orgA->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
        ]);
        $orgA->save();

        $orgB = Organization::create([
            'company_code' => 'NOSALES',
            'org_name' => 'No Sales',
            'org_email' => 'nosales@test.com',
            'primary_tel' => '0700000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ModuleRegistry::cascade([
                'sales' => false,
            ]),
        ]);

        $gateA = app(CapabilityGate::class)->forOrganization($orgA->fresh());
        $gateB = app(CapabilityGate::class)->forOrganization($orgB->fresh());

        $this->assertTrue($gateA->enabled('sales.reports'));
        $this->assertTrue($gateA->enabled('sales.backend'));
        $this->assertFalse($gateB->enabled('sales'));
        $this->assertFalse($gateB->enabled('sales.reports'));
    }

    public function test_legacy_reports_flag_expands_to_domain_report_modules(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ['reports' => false, 'sales' => true];
        $org->save();

        $gate = app(CapabilityGate::class)->forOrganization($org->fresh());

        $this->assertFalse($gate->enabled('sales.reports'));
        $this->assertFalse($gate->enabled('inventory.reports'));
    }

    public function test_distribution_requires_mobile_sales(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => false,
            'distribution' => true,
            'distribution.dashboard' => true,
            'distribution.reports' => true,
        ]);

        $this->assertFalse($modules['distribution']);
        $this->assertFalse($modules['distribution.dashboard']);
        $this->assertFalse($modules['distribution.reports']);
    }

    public function test_enabling_distribution_without_sales_keeps_distribution_off(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => false,
            'distribution' => true,
        ]);

        $this->assertFalse($modules['distribution']);
        $this->assertFalse($modules['sales.mobile']);
    }

    public function test_mobile_sales_without_distribution_is_allowed(): void
    {
        $modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.mobile' => true,
            'distribution' => false,
            'distribution.dashboard' => false,
        ]);

        $this->assertTrue($modules['sales.mobile']);
        $this->assertFalse($modules['distribution']);
    }

    public function test_supermarket_profile_enables_pos_without_mobile_or_distribution(): void
    {
        $profile = config('erp.profiles.supermarket');
        $this->assertNotNull($profile);

        $modules = ModuleRegistry::cascade($profile['modules']);

        $this->assertTrue($modules['sales.pos']);
        $this->assertTrue($modules['sales.backend']);
        $this->assertFalse($modules['sales.mobile']);
        $this->assertFalse($modules['distribution']);
        $this->assertSame(['pos', 'backend'], $profile['default_channels']);
    }
}
