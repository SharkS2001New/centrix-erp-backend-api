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

        $this->getJson('/api/v1/reports/stock-movement?branch_id='.$admin->branch_id.'&per_page=20')
            ->assertOk();

        $this->getJson('/api/v1/reports/stock-transfers?branch_id='.$admin->branch_id.'&per_page=20')
            ->assertOk();
    }

    public function test_stock_clerk_can_load_inventory_report_endpoints_with_inventory_permissions_only(): void
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
                'inventory.reports' => true,
                'sales' => true,
                'sales.backend' => true,
            ],
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Reports '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', [
                'inventory.stock.view',
                'inventory.movements.view',
                'inventory.transfers.view',
            ])
            ->pluck('id');
        $this->assertCount(3, $permissionIds);

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
            'username' => 'stock_clerk_reports_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Clerk Reports',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($clerk);

        $branchQuery = 'branch_id='.$admin->branch_id.'&per_page=20';

        $this->getJson('/api/v1/reports/stock-on-hand?'.$branchQuery)->assertOk();
        $this->getJson('/api/v1/reports/stock-movement?'.$branchQuery)->assertOk();
        $this->getJson('/api/v1/reports/stock-transfers?'.$branchQuery)->assertOk();
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
        $this->assertFalse($capabilities['permissions']['inventory.receipts.view'] ?? false);
        $this->assertFalse($capabilities['assigned_permissions']['inventory.receipts.view'] ?? false);

        $backoffice = collect($capabilities['workspaces'] ?? [])->firstWhere('id', 'backoffice');
        $this->assertNotNull($backoffice);
        $this->assertSame('/dashboard', $backoffice['home_path']);
    }

    public function test_stock_clerk_can_list_and_create_stock_take_sessions(): void
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
            ],
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Stock Take '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', [
                'inventory.stock_take.view',
                'inventory.stock_take.create',
            ])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);

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
            'username' => 'stock_clerk_take_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Clerk Stock Take',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($clerk);

        $this->getJson('/api/v1/stock-take-sessions?per_page=20')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $created = $this->postJson('/api/v1/stock-take-sessions', [
            'session_code' => 'ST-TEST-'.uniqid(),
            'stock_location' => 'shop',
            'branch_id' => $admin->branch_id,
        ])
            ->assertCreated()
            ->json();

        $this->postJson("/api/v1/inventory/stock-take/{$created['id']}/initialize", [
            'branch_id' => $admin->branch_id,
        ])->assertOk();
    }

    public function test_stock_clerk_can_read_catalog_reference_data_for_stock_take_view(): void
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
            ],
        ]);

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Stock Clerk Catalog Ref '.uniqid()],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', [
                'inventory.stock_take.view',
                'inventory.transfers.view',
            ])
            ->pluck('id');
        $this->assertCount(2, $permissionIds);

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
            'username' => 'stock_clerk_catalog_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Stock Clerk Catalog Ref',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        Sanctum::actingAs($clerk);

        $this->getJson('/api/v1/uoms?per_page=500')->assertOk();
        $this->getJson('/api/v1/products?per_page=20')->assertOk();
        $this->getJson('/api/v1/vats?per_page=50')->assertOk();
        $this->getJson('/api/v1/categories?per_page=50')->assertOk();
    }
}
