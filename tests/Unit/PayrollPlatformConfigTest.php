<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Models\Organization;
use App\Services\Hr\HrPayrollSettingsResolver;
use App\Services\OrganizationPlatformConfigService;
use App\Services\Payroll\KenyaPayrollSettingsResolver;
use App\Services\Payroll\KenyaStatutoryCalculator;
use App\Services\Payroll\PayrollEarningsService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PayrollPlatformConfigTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function createOrg(string $code): Organization
    {
        return Organization::create([
            'company_code' => $code,
            'org_name' => 'Payroll Config Test',
            'org_email' => strtolower($code).'@test.com',
            'primary_tel' => '0700111222',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }

    protected function createEmployee(Organization $org): Employee
    {
        return Employee::create([
            'organization_id' => $org->id,
            'employee_code' => 'EMP-'.uniqid(),
            'payroll_number' => 'PAY-'.uniqid(),
            'first_name' => 'Test',
            'last_name' => 'Worker',
            'full_name' => 'Test Worker',
            'employment_status' => 'active',
            'is_active' => true,
            'base_salary' => 30000,
        ]);
    }

    public function test_platform_admin_can_set_fixed_thirty_day_basis_per_org(): void
    {
        $org = $this->createOrg('PAY30'.uniqid());
        app(OrganizationPlatformConfigService::class)->applyPayrollPlatformConfig($org, [
            'payroll_month_days_basis' => 'fixed_30',
        ]);

        $hr = HrPayrollSettingsResolver::forOrganization($org->fresh());
        $this->assertSame('fixed_30', $hr['payroll_month_days_basis']);

        $expected = app(PayrollEarningsService::class)->expectedWorkDays(
            $this->createEmployee($org),
            '2026-02-01',
            '2026-02-28',
        );
        $this->assertSame(30.0, $expected);
    }

    public function test_calendar_basis_uses_days_in_pay_period(): void
    {
        $org = $this->createOrg('PAYCAL'.uniqid());
        app(OrganizationPlatformConfigService::class)->applyPayrollPlatformConfig($org, [
            'payroll_month_days_basis' => 'calendar',
        ]);

        $expected = app(PayrollEarningsService::class)->expectedWorkDays(
            $this->createEmployee($org),
            '2026-02-01',
            '2026-02-28',
        );
        $this->assertSame(28.0, $expected);
    }

    public function test_org_shif_minimum_overrides_platform_default_in_calculator(): void
    {
        $org = $this->createOrg('PAYSHIF'.uniqid());
        app(OrganizationPlatformConfigService::class)->applyPayrollPlatformConfig($org, [
            'shif_minimum_monthly' => 350,
        ]);

        $cfg = KenyaPayrollSettingsResolver::resolveForOrganizationId((int) $org->id);
        $this->assertSame(350.0, $cfg['shif']['minimum_monthly']);

        $result = app(KenyaStatutoryCalculator::class)->calculateMonthly(8000, 0, 0, (int) $org->id);
        $this->assertSame(350.0, $result['shif']);
    }

    public function test_tenant_hr_settings_update_cannot_change_platform_payroll_keys(): void
    {
        $org = $this->createOrg('PAYLOCK'.uniqid());
        app(OrganizationPlatformConfigService::class)->applyPayrollPlatformConfig($org, [
            'payroll_month_days_basis' => 'fixed_30',
            'shif_minimum_monthly' => 400,
        ]);

        $filtered = app(OrganizationPlatformConfigService::class)->filterOrgManagerHrPayrollPayload([
            'payroll_month_days_basis' => 'calendar',
            'shif_minimum_monthly' => 100,
            'require_payroll_approval' => true,
        ]);

        $this->assertArrayNotHasKey('payroll_month_days_basis', $filtered);
        $this->assertArrayNotHasKey('shif_minimum_monthly', $filtered);
        $this->assertTrue($filtered['require_payroll_approval']);
    }
}
