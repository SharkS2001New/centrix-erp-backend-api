<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleRegistry;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackofficeFinanceReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_backoffice_finance_slugs_allow_sales_or_accounting_reports(): void
    {
        $modules = ModuleRegistry::reportAccessModulesForSlug('profit-loss');

        $this->assertSame(['sales.reports', 'accounting.reports'], $modules);
        $this->assertSame(['sales.reports', 'accounting.reports'], ModuleRegistry::reportAccessModulesForSlug('kra-receipts'));
        $this->assertSame(['sales.reports'], ModuleRegistry::reportAccessModulesForSlug('daily-sales'));
    }

    public function test_profit_loss_accessible_with_sales_reports_only(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.backend' => true,
            'sales.reports' => true,
            'accounting' => false,
            'accounting.reports' => false,
        ]);
        $org->save();

        $gate = app(CapabilityGate::class)->forOrganization($org->fresh());
        $this->assertTrue($gate->enabled('sales.reports'));
        $this->assertFalse($gate->enabled('accounting.reports'));

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/profit-loss?from_date=2026-01-01&to_date=2026-06-30')
            ->assertOk();
    }

    public function test_profit_loss_forbidden_without_sales_or_accounting_reports(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'sales' => true,
            'sales.backend' => true,
            'sales.reports' => false,
            'accounting' => false,
            'accounting.reports' => false,
        ]);
        $org->save();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/profit-loss?from_date=2026-01-01&to_date=2026-06-30')
            ->assertForbidden()
            ->assertJsonPath('module', 'sales.reports');
    }
}
