<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockClerkAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_stock_clerk_can_list_branches_and_stock_without_admin_module(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $admin->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        Organization::query()->whereKey($admin->organization_id)->update([
            'enabled_modules' => [
                'inventory' => true,
                'inventory.dashboard' => true,
                'inventory.reports' => true,
                'customers_suppliers' => true,
                'sales' => true,
                'sales.backend' => true,
            ],
        ]);

        $gate = app(\App\Services\Erp\ErpContext::class)->gateForUser($admin->fresh());
        $this->assertFalse($gate->enabled('admin'));
        $this->assertTrue($gate->enabled('inventory'));

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Access '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', [
                'inventory.stock.view',
                'inventory.stock_take.view',
                'inventory.stock_take.create',
                'inventory.movements.view',
                'inventory.transfers.view',
                'inventory.transfers.create',
                'inventory.receipts.view',
                'inventory.receipts.create',
                'inventory.adjustments.view',
                'inventory.adjustments.create',
                'inventory.damages.view',
                'inventory.damages.create',
            ])
            ->pluck('id');
        $this->assertNotEmpty($permissionIds);

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permissionId],
                [],
            );
        }

        $clerk = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'stock_clerk_access_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Clerk Access',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($clerk);

        $this->getJson('/api/v1/branches?per_page=50')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/v1/categories?per_page=50')
            ->assertOk();

        $this->getJson('/api/v1/reports/stock-on-hand?branch_id='.$admin->branch_id.'&per_page=20')
            ->assertOk();
    }

    public function test_stock_clerk_capabilities_include_inventory_permissions_and_workspace_home(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $admin->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        Organization::query()->whereKey($admin->organization_id)->update([
            'enabled_modules' => [
                'inventory' => true,
                'inventory.dashboard' => true,
                'inventory.reports' => true,
                'sales' => true,
                'sales.backend' => true,
            ],
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Capabilities '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', ['inventory.stock.view', 'inventory.receipts.view'])
            ->pluck('id');
        $this->assertNotEmpty($permissionIds);

        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(
                ['role_id' => $role->id, 'permission_id' => $permissionId],
                [],
            );
        }

        $clerk = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'stock_clerk_caps_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Clerk Caps',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($clerk);

        $capabilities = $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->json();

        $this->assertTrue($capabilities['permissions']['inventory.stock.view'] ?? false);
        $this->assertTrue($capabilities['permissions']['dashboard.inventory.view'] ?? false);

        $backoffice = collect($capabilities['workspaces'] ?? [])->firstWhere('id', 'backoffice');
        $this->assertNotNull($backoffice);
        $this->assertSame('/inventory/stock', $backoffice['home_path']);
    }
}
