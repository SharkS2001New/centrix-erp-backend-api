<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\CapabilityGate;
use App\Services\OrganizationProvisioningService;
use Tests\TestCase;

class CapabilityGateApplicationOverridesTest extends TestCase
{
    public function test_sparse_enabled_modules_do_not_reenable_profile_applications(): void
    {
        $provisioner = new ApplicationProvisioner;
        $modules = $provisioner->enabledModulesFromApplications([
            'pos' => false,
            'backoffice' => true,
            'distribution' => false,
            'accounting' => false,
            'hr' => false,
            'admin' => false,
        ]);

        $normalized = app(OrganizationProvisioningService::class)->normalizeEnabledModules($modules);

        $org = new Organization([
            'company_code' => 'DEMO',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $normalized,
            'module_settings' => [],
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);
        $applications = $provisioner->applicationsFromEnabledModules($gate->allModules());

        $this->assertTrue($applications['backoffice']);
        $this->assertFalse($applications['pos']);
        $this->assertFalse($applications['accounting']);
        $this->assertFalse($applications['hr']);
        $this->assertFalse($applications['distribution']);
        $this->assertFalse($applications['admin']);
        $this->assertFalse($gate->enabled('accounting'));
        $this->assertFalse($gate->enabled('hr_payroll'));
        $this->assertFalse($gate->enabled('admin'));
        $this->assertTrue($gate->enabled('sales.backend'));
        $this->assertTrue($gate->enabled('inventory'));
    }

    public function test_null_enabled_modules_falls_back_to_deployment_profile(): void
    {
        $org = new Organization([
            'company_code' => 'DEMO',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => null,
            'module_settings' => [],
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);

        $this->assertTrue($gate->enabled('accounting'));
        $this->assertTrue($gate->enabled('hr_payroll'));
        $this->assertTrue($gate->enabled('admin'));
    }

    public function test_sparse_distribution_without_sales_mobile_stays_enabled(): void
    {
        $org = new Organization([
            'company_code' => 'DEMO',
            'deployment_profile' => 'distribution',
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'sales.dashboard' => true,
                'sales.reports' => true,
                'inventory' => true,
                'customers_suppliers' => true,
                'distribution' => true,
                'distribution.dashboard' => true,
                'distribution.reports' => true,
                'admin' => true,
            ],
            'module_settings' => [],
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);

        $this->assertTrue($gate->enabled('distribution'));
        $this->assertTrue($gate->enabled('sales.mobile'));
        $this->assertTrue($gate->enabled('customers_suppliers'));
        $this->assertTrue($gate->distributionOpsEnabled());
    }
}
