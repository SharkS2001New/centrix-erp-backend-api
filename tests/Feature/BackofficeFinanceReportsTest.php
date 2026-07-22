<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ModuleRegistry;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackofficeFinanceReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::where('username', 'admin')->first();
        if ($admin) {
            $this->ensureActiveSubscription($admin);
        }
    }

    protected function ensureActiveSubscription(User $user): void
    {
        if (! $user->organization_id) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );
    }

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

    public function test_profit_loss_includes_non_completed_orders_except_cancelled_and_expired(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $baseline = $this->getJson('/api/v1/reports/profit-loss?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->json('data.0');

        Sale::create([
            'order_num' => 99110,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => null,
            'status' => 'pending_approval',
            'total_vat' => 200,
            'order_total' => 1200,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'archived' => 0,
            'completed_at' => null,
            'created_at' => '2026-06-15 10:00:00',
        ]);

        $current = $this->getJson('/api/v1/reports/profit-loss?from_date=2026-06-01&to_date=2026-06-30')
            ->assertOk()
            ->json('data.0');

        $this->assertEqualsWithDelta(
            (float) ($baseline['gross_revenue'] ?? 0) + 1200.0,
            (float) ($current['gross_revenue'] ?? 0),
            0.01,
        );
        $this->assertEqualsWithDelta(
            (float) ($baseline['net_revenue'] ?? 0) + 1000.0,
            (float) ($current['net_revenue'] ?? 0),
            0.01,
        );
    }

    public function test_profit_loss_by_product_uses_package_cost_per_sold_quantity(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = \App\Models\Product::query()
            ->with('unit')
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();

        $uom = $product->unit ?? \App\Models\Uom::query()->findOrFail($product->unit_id);
        $originalFactor = (float) $uom->conversion_factor;
        $uom->forceFill(['conversion_factor' => 18])->save();
        $product->forceFill(['last_cost_price' => 442, 'unit_price' => 460])->save();

        $factor = 18.0;
        $cartonsSold = 2;
        $baseQty = $cartonsSold * $factor;

        $sale = Sale::create([
            'order_num' => 99221,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'total_vat' => 0,
            'order_total' => 920,
            'payment_status' => 'paid',
            'amount_paid' => 920,
            'archived' => 0,
            'completed_at' => '2026-06-20 12:00:00',
            'created_at' => '2026-06-20 12:00:00',
        ]);

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => $baseQty,
            'uom' => $product->uom ?? $uom->measure_name,
            'selling_price' => 460,
            'discount_given' => 0,
            'product_vat' => 0,
            'amount' => 920,
            'on_wholesale_retail' => 0,
        ]);

        $row = collect($this->getJson('/api/v1/reports/profit-loss-by-product?from_date=2026-06-20&to_date=2026-06-20')
            ->assertOk()
            ->json('data'))
            ->firstWhere('product_code', $product->product_code);

        $uom->forceFill(['conversion_factor' => $originalFactor])->save();

        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(884.0, (float) ($row['cogs'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(36.0, (float) ($row['gross_profit'] ?? 0), 0.01);
        $this->assertEqualsWithDelta(2.0, (float) ($row['qty_sold_packages'] ?? 0), 0.01);
        $this->assertNotEmpty($row['qty_sold_label'] ?? null);

        // Summary is org-wide for the day (may include other seed sales) — only
        // assert the product-level formula: gross − COGS.
        $this->assertEqualsWithDelta(
            (float) ($row['gross_revenue'] ?? 0) - (float) ($row['cogs'] ?? 0),
            (float) ($row['gross_profit'] ?? 0),
            0.01,
        );
    }

    public function test_profit_loss_by_product_uses_gross_selling_amount_not_net_ex_vat(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $product = \App\Models\Product::query()
            ->with('unit')
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();

        $uom = $product->unit ?? \App\Models\Uom::query()->findOrFail($product->unit_id);
        $originalFactor = (float) $uom->conversion_factor;
        $originalCost = (float) $product->last_cost_price;
        $originalPrice = (float) $product->unit_price;
        $uom->forceFill(['conversion_factor' => 1])->save();
        $product->forceFill(['last_cost_price' => 4790, 'unit_price' => 4860])->save();

        // Isolated day so seed sales cannot mix into this product row.
        $day = '2025-03-15';
        $qty = 80;
        $gross = 390400.0;
        $vat = 53848.28;

        $sale = Sale::create([
            'order_num' => 99222,
            'branch_id' => $admin->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'status' => 'processed',
            'total_vat' => $vat,
            'order_total' => $gross,
            'payment_status' => 'paid',
            'amount_paid' => $gross,
            'archived' => 0,
            'completed_at' => $day.' 12:00:00',
        ]);
        $sale->forceFill(['created_at' => $day.' 12:00:00'])->save();

        \App\Models\SaleItem::create([
            'sale_id' => $sale->id,
            'product_code' => $product->product_code,
            'line_no' => 1,
            'item_code' => '1',
            'quantity' => $qty,
            'uom' => $product->uom ?? $uom->measure_name,
            'selling_price' => 4860,
            'discount_given' => 0,
            'product_vat' => $vat,
            'amount' => $gross,
            'on_wholesale_retail' => 0,
        ]);

        $row = collect($this->getJson("/api/v1/reports/profit-loss-by-product?from_date={$day}&to_date={$day}")
            ->assertOk()
            ->json('data'))
            ->firstWhere('product_code', $product->product_code);

        $uom->forceFill(['conversion_factor' => $originalFactor])->save();
        $product->forceFill(['last_cost_price' => $originalCost, 'unit_price' => $originalPrice])->save();

        $this->assertNotNull($row);
        $grossRevenue = (float) ($row['gross_revenue'] ?? 0);
        $netRevenue = (float) ($row['net_revenue'] ?? 0);
        $cogs = (float) ($row['cogs'] ?? 0);
        $grossProfit = (float) ($row['gross_profit'] ?? 0);

        $this->assertEqualsWithDelta(383200.0, $cogs, 0.01);
        $this->assertEqualsWithDelta(7200.0, $grossProfit, 0.01);
        $this->assertEqualsWithDelta($grossRevenue - $cogs, $grossProfit, 0.01);
        $this->assertNotEqualsWithDelta(
            $netRevenue - $cogs,
            $grossProfit,
            1.0,
            'Gross profit must not use net-ex-VAT minus COGS when VAT is present.',
        );

        $pl = $this->getJson("/api/v1/reports/profit-loss?from_date={$day}&to_date={$day}")
            ->assertOk()
            ->json('data.0');
        $this->assertEqualsWithDelta(
            (float) ($pl['gross_revenue'] ?? 0) - (float) ($pl['cogs'] ?? 0),
            (float) ($pl['gross_profit'] ?? 0),
            0.01,
        );
    }

    public function test_profit_loss_by_product_accessible_with_sales_reports_only(): void
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

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/profit-loss-by-product?from_date=2026-01-01&to_date=2026-06-30')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);
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

    public function test_hr_reports_accessible_when_hr_domain_enabled_even_if_profile_disabled_reports(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->deployment_profile = 'small_shop';
        $org->enabled_modules = ['hr_payroll' => true];
        $org->save();

        $gate = app(CapabilityGate::class)->forOrganization($org->fresh());
        $this->assertTrue($gate->enabled('hr_payroll'));
        $this->assertTrue($gate->reportModuleEnabled('hr_payroll.reports'));

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/payroll-summary?from_date=2026-01-01&to_date=2026-06-30')
            ->assertOk();
    }

    public function test_hr_reports_respect_explicit_org_disable(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $org->enabled_modules = ModuleRegistry::cascade([
            'hr_payroll' => true,
            'hr_payroll.reports' => false,
        ]);
        $org->save();

        $gate = app(CapabilityGate::class)->forOrganization($org->fresh());
        $this->assertFalse($gate->reportModuleEnabled('hr_payroll.reports'));

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/reports/payroll-summary?from_date=2026-01-01&to_date=2026-06-30')
            ->assertForbidden();
    }
}
