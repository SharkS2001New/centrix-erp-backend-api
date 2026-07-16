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
