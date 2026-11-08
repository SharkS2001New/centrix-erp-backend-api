<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\CapabilityGate;
use App\Services\OrganizationProvisioningService;
use Tests\TestCase;

class CapabilityGateApplicationOverridesTest extends TestCase
{
    /**
     * Unsaved DEMO orgs resolve as unpaid/free and package entitlements hide apps.
     * Use the platform company code so these tests cover module-map behaviour only.
     */
    protected function organization(array $attributes): Organization
    {
        return new Organization(array_merge([
            'company_code' => (string) config('erp.platform_company_code', 'PLATFORM'),
            'module_settings' => [],
        ], $attributes));
    }

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

        $org = $this->organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => $normalized,
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
        $org = $this->organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => null,
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);

        $this->assertTrue($gate->enabled('accounting'));
        $this->assertTrue($gate->enabled('hr_payroll'));
        $this->assertTrue($gate->enabled('admin'));
    }

    public function test_null_enabled_modules_hotel_bar_does_not_enable_accounting_by_default(): void
    {
        $org = $this->organization([
            'deployment_profile' => 'hotel_bar',
            'enabled_modules' => null,
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);

        $this->assertTrue($gate->enabled('hospitality.bar_pos'));
        $this->assertTrue($gate->enabled('hospitality.backend'));
        $this->assertTrue($gate->enabled('admin'));
        $this->assertFalse($gate->enabled('accounting'));
        $this->assertFalse($gate->enabled('hr_payroll'));
    }

    public function test_sparse_distribution_without_sales_mobile_stays_enabled(): void
    {
        $org = $this->organization([
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
        ]);

        $gate = app(CapabilityGate::class)->forOrganization($org);

        $this->assertTrue($gate->enabled('distribution'));
        $this->assertTrue($gate->enabled('sales.mobile'));
        $this->assertTrue($gate->enabled('customers_suppliers'));
        $this->assertTrue($gate->distributionOpsEnabled());
    }
}
