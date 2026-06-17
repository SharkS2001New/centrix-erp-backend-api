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

    public function test_disabling_domain_disables_children(): void
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

    public function test_domain_on_child_reports_can_differ_per_org(): void
    {
        $orgA = Organization::where('company_code', 'DEMO')->firstOrFail();
        $orgA->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.backend' => true,
            'sales.reports' => true,
        ]);
        $orgA->save();

        $orgB = Organization::create([
            'company_code' => 'REPORTB',
            'org_name' => 'Reports B',
            'org_email' => 'b@test.com',
            'primary_tel' => '0700000000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'enabled_modules' => ModuleRegistry::cascade([
                'sales' => true,
                'sales.backend' => true,
                'sales.reports' => false,
            ]),
        ]);

        $gateA = app(CapabilityGate::class)->forOrganization($orgA->fresh());
        $gateB = app(CapabilityGate::class)->forOrganization($orgB->fresh());

        $this->assertTrue($gateA->enabled('sales.reports'));
        $this->assertFalse($gateB->enabled('sales.reports'));
        $this->assertTrue($gateB->enabled('sales.backend'));
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
}
