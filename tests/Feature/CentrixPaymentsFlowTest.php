<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureOrganizationLicenseActive;
use App\Models\MpesaPaybillAccount;
use App\Models\Organization;
use App\Models\PaymentAccount;
use App\Models\Permission;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CentrixPaymentsFlowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([EnsureOrganizationLicenseActive::class]);

        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
        PermissionMatrixService::ensure();

        $this->org = Organization::findOrFail($this->user->organization_id);
        $this->enableCentrixPayments();
    }

    protected function enableCentrixPayments(): void
    {
        $modules = is_array($this->org->enabled_modules) ? $this->org->enabled_modules : [];
        $modules['centrix_payments'] = true;
        $modules['centrix_payments.reports'] = true;
        $settings = is_array($this->org->module_settings) ? $this->org->module_settings : [];
        $settings['centrix_payments'] = array_merge($settings['centrix_payments'] ?? [], [
            'enable_centrix_payments' => true,
        ]);
        $this->org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
        $this->org->refresh();
    }

    protected function disableCentrixPayments(): void
    {
        $modules = is_array($this->org->enabled_modules) ? $this->org->enabled_modules : [];
        $modules['centrix_payments'] = false;
        $modules['centrix_payments.reports'] = false;
        $settings = is_array($this->org->module_settings) ? $this->org->module_settings : [];
        $settings['centrix_payments'] = ['enable_centrix_payments' => false];
        $this->org->update([
            'enabled_modules' => $modules,
            'module_settings' => $settings,
        ]);
        $this->org->refresh();
    }

    public function test_module_disabled_blocks_centrix_payments_api(): void
    {
        $this->disableCentrixPayments();

        $this->getJson('/api/v1/centrix-payments/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/centrix-payments/payment-accounts')->assertStatus(403);
    }

    public function test_payment_accounts_sync_from_mpesa_paybill(): void
    {
        $mpesa = MpesaPaybillAccount::query()->create([
            'organization_id' => $this->org->id,
            'name' => 'Nairobi Till',
            'primary_short_code' => '500001',
            'till_number' => '500001',
            'is_default' => true,
            'is_active' => true,
            'enable_stk_push' => true,
        ]);

        $this->postJson('/api/v1/centrix-payments/payment-accounts/sync')
            ->assertOk()
            ->assertJsonPath('synced', 1);

        $this->assertDatabaseHas('payment_accounts', [
            'organization_id' => $this->org->id,
            'provider' => PaymentAccount::PROVIDER_MPESA,
            'provider_account_id' => $mpesa->id,
            'shortcode' => '500001',
        ]);

        $this->getJson('/api/v1/centrix-payments/payment-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.provider', PaymentAccount::PROVIDER_MPESA);
    }

    public function test_organization_cannot_read_other_org_payment_accounts(): void
    {
        $otherOrg = Organization::query()->where('id', '!=', $this->org->id)->first();
        if (! $otherOrg) {
            $this->markTestSkipped('Requires a second organization in test database.');
        }

        PaymentAccount::query()->create([
            'organization_id' => $otherOrg->id,
            'provider' => PaymentAccount::PROVIDER_BANK,
            'account_type' => 'bank_account',
            'account_name' => 'Other Org Account',
            'provider_account_type' => PaymentAccount::class,
            'provider_account_id' => 0,
            'status' => PaymentAccount::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/centrix-payments/payment-accounts')
            ->assertOk()
            ->assertJsonMissing(['account_name' => 'Other Org Account']);
    }

    public function test_dashboard_returns_availability_summary(): void
    {
        $this->getJson('/api/v1/centrix-payments/dashboard')
            ->assertOk()
            ->assertJsonStructure([
                'availability' => ['module_enabled', 'mpesa_stk_available', 'mpesa_configured'],
                'totals' => ['today_collections', 'successful_payments', 'pending_payments'],
            ])
            ->assertJsonPath('availability.module_enabled', true);
    }

    public function test_org_admin_can_access_centrix_payments_without_role_matrix_grant(): void
    {
        \Illuminate\Support\Facades\DB::table('role_permissions')
            ->whereIn('permission_id', Permission::query()->where('module', 'centrix_payments')->pluck('id'))
            ->delete();

        $this->user->update(['is_admin' => true]);
        $this->user->refresh();

        $this->getJson('/api/v1/centrix-payments/dashboard')
            ->assertOk()
            ->assertJsonPath('availability.module_enabled', true);
    }

    public function test_platform_admin_can_disable_centrix_payments_despite_stale_sales_platform_flag(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $applications = [
            'pos' => true,
            'backoffice' => false,
            'hotel_bar_pos' => false,
            'hospitality_backoffice' => false,
            'distribution' => false,
            'accounting' => false,
            'centrix_payments' => true,
            'hr' => false,
            'admin' => true,
        ];

        $this->patchJson("/api/v1/admin/organizations/{$this->org->id}", [
            'applications' => $applications,
            'sales_platform' => ['enable_centrix_payments' => true],
        ])->assertOk()
            ->assertJsonPath('effective_modules.centrix_payments', true);

        $applications['centrix_payments'] = false;

        $response = $this->patchJson("/api/v1/admin/organizations/{$this->org->id}", [
            'applications' => $applications,
            'sales_platform' => ['enable_centrix_payments' => true],
        ])->assertOk();

        $response->assertJsonPath('effective_modules.centrix_payments', false);
        $this->assertFalse($response->json('effective_modules')['centrix_payments.reports'] ?? true);
        $response->assertJsonPath('sales_platform.enable_centrix_payments', false);

        $this->org->refresh();
        $this->assertFalse((bool) ($this->org->enabled_modules['centrix_payments'] ?? false));
        $this->assertFalse((bool) ($this->org->enabled_modules['centrix_payments.reports'] ?? false));
        $this->assertFalse((bool) ($this->org->module_settings['centrix_payments']['enable_centrix_payments'] ?? false));

        $this->getJson('/api/v1/centrix-payments/dashboard')->assertStatus(403);
    }
}
