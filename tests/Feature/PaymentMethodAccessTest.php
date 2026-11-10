<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PaymentMethodAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function ensureOrgSubscription(User $user): void
    {
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

    public function test_mobile_driver_can_list_payment_methods_for_delivery_collection(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);

        $driverRole = Role::where('role_name', 'Driver')->firstOrFail();
        PaymentMethod::create([
            'organization_id' => $admin->organization_id,
            'method_name' => 'Driver Cash',
            'method_code' => 'DRIVER_CASH',
            'requires_reference' => false,
            'is_active' => true,
        ]);

        $driver = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $driverRole->id,
            'username' => 'driver_payment_methods_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Driver Payment Methods',
            'access_scope' => 'branch',
            'is_mobile_user' => true,
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        $this->grantRolePermissions($driverRole->id, ['driver.mobile']);

        Sanctum::actingAs($driver);

        $response = $this->getJson('/api/v1/payment-methods?per_page=50&filter%5Bis_active%5D=1')
            ->assertOk();

        $codes = collect($response->json('data'))->pluck('method_code');
        $this->assertTrue($codes->contains('DRIVER_CASH'));
    }

    public function test_sales_cashier_can_list_payment_methods_for_collect_payment(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Collect Payment Cashier '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'cashier_pay_methods_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Cashier Collect Payment',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos'],
            'is_active' => true,
        ]);

        $this->grantRolePermissions($role->id, [
            'sales.orders.view',
            'sales.orders.edit',
            'payments.sale_payments.view',
            'payments.sale_payments.create',
        ]);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/payment-methods?per_page=50&filter%5Bis_active%5D=1')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_collect_payment_lists_methods_when_admin_module_disabled(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);

        Organization::query()->whereKey($admin->organization_id)->update([
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'payments' => true,
                'distribution' => true,
            ],
        ]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($admin->fresh());
        $this->assertFalse($gate->enabled('admin'), 'Precondition: admin module must be off');
        $this->assertTrue($gate->enabled('sales'), 'Precondition: sales module must be on');

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'No Admin Collect Pay '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'no_admin_collect_pay_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'No Admin Collect Pay',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos'],
            'is_active' => true,
        ]);

        $this->grantRolePermissions($role->id, [
            'sales.orders.view',
            'payments.sale_payments.create',
        ]);

        Sanctum::actingAs($cashier);

        $this->getJson('/api/v1/payment-methods?per_page=50&filter%5Bis_active%5D=1')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_mobile_driver_cannot_manage_payment_methods(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);

        $driverRole = Role::where('role_name', 'Driver')->firstOrFail();
        $driver = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $driverRole->id,
            'username' => 'driver_no_payment_manage_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Driver No Payment Manage',
            'access_scope' => 'branch',
            'is_mobile_user' => true,
            'login_channels' => ['mobile'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($driver);

        $this->postJson('/api/v1/payment-methods', [
            'method_name' => 'Driver Created Method',
            'method_code' => 'DRIVER_CREATED',
            'requires_reference' => false,
            'is_active' => true,
        ])->assertForbidden();
    }

    public function test_system_cash_method_cannot_be_deleted(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);
        Sanctum::actingAs($admin);

        $cash = PaymentMethod::query()
            ->where('organization_id', $admin->organization_id)
            ->whereRaw('UPPER(method_code) = ?', ['CASH'])
            ->firstOrFail();

        $this->deleteJson('/api/v1/payment-methods/'.$cash->id)
            ->assertStatus(422);

        $this->assertDatabaseHas('payment_methods', ['id' => $cash->id, 'method_code' => 'CASH']);
    }

    public function test_recording_payments_filter_follows_org_sales_toggles(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $this->ensureOrgSubscription($admin);

        $org = Organization::query()->findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mpesa_amount' => true,
            'enable_equity_bank' => true,
            'enable_kcb_bank' => true,
            'enable_other_bank' => false,
            'enable_cheque' => true,
            'enable_credit_payment' => false,
        ]);
        $org->forceFill(['module_settings' => $settings])->save();

        app(\App\Services\Organization\OrganizationReferenceDataService::class)
            ->ensurePaymentMethods((int) $org->id);

        Sanctum::actingAs($admin);

        $codes = collect(
            $this->getJson('/api/v1/payment-methods?recording_payments=1&include_credit=1')
                ->assertOk()
                ->json('data'),
        )->pluck('method_code')->map(fn ($code) => strtoupper((string) $code));

        $this->assertTrue($codes->contains('CASH'));
        $this->assertTrue($codes->contains('MPESA'));
        $this->assertTrue($codes->contains('EQUITY'));
        $this->assertTrue($codes->contains('KCB'));
        $this->assertTrue($codes->contains('CHEQUE'));
        $this->assertFalse($codes->contains('BANK'));
        $this->assertFalse($codes->contains('CARD'));
        $this->assertFalse($codes->contains('VOUCHER'));
        $this->assertFalse($codes->contains('POINTS'));
        $this->assertFalse($codes->contains('CREDIT'));
    }

    /** @param  list<string>  $codes */
    protected function grantRolePermissions(int $roleId, array $codes): void
    {
        $permissionIds = Permission::query()
            ->whereIn('permission_code', $codes)
            ->pluck('id');
        $this->assertNotEmpty($permissionIds, 'Missing permissions: '.implode(', ', $codes));

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $roleId, 'permission_id' => $permissionId],
                [],
            );
        }
    }
}
