<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ModuleScopedPermissionsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_permission_matrix_hides_disabled_module_groups(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'admin' => true,
                'sales' => true,
                'sales.backend' => true,
                'inventory' => true,
                'accounting' => false,
                'hr_payroll' => false,
            ],
        ]);

        Sanctum::actingAs($admin);

        $groups = collect(
            $this->getJson('/api/v1/roles/permissions/matrix')
                ->assertOk()
                ->json('groups'),
        )->pluck('module');

        $this->assertTrue($groups->contains('sales'));
        $this->assertTrue($groups->contains('inventory'));
        $this->assertFalse($groups->contains('accounting'));
        $this->assertFalse($groups->contains('hr'));
    }

    public function test_admin_capability_map_is_limited_to_enabled_modules(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'inventory' => true,
                'accounting' => false,
            ],
        ]);

        $gate = app(ErpContext::class)->gateForUser($admin);
        $map = app(UserPermissionService::class)->permissionMapForUser($admin, $gate);

        $this->assertTrue($map['sales.orders.view'] ?? false);
        $this->assertTrue($map['inventory.stock.view'] ?? false);
        $this->assertFalse($map['accounting.chart_of_accounts.view'] ?? false);
    }

    public function test_org_admin_capability_map_includes_mobile_sales_permissions(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $gate = app(ErpContext::class)->gateForUser($admin);
        $map = app(UserPermissionService::class)->permissionMapForUser($admin, $gate);

        $this->assertTrue($map['mobile_sales.dashboard.view'] ?? false);
        $this->assertTrue($map['mobile_sales.orders.view'] ?? false);
        $this->assertTrue($map['mobile_sales.orders.create'] ?? false);
    }

    public function test_sales_orders_create_role_expands_mobile_sales_permission_map(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['sales'] = array_merge($settings['sales'] ?? [], [
            'enable_mobile_orders' => true,
        ]);
        $org->update(['module_settings' => $settings]);

        $role = \App\Models\Role::create([
            'role_name' => 'Field Sales Create Only',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $createId = (int) Permission::where('permission_code', 'sales.orders.create')->value('id');
        $this->assertGreaterThan(0, $createId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $createId,
        ]);

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'field_sales_create_only',
            'password' => bcrypt('password'),
            'full_name' => 'Field Sales Create Only',
            'access_scope' => 'branch',
            'is_active' => true,
            'login_channels' => ['mobile'],
        ]);

        $gate = app(ErpContext::class)->gateForUser($user);
        $map = app(UserPermissionService::class)->permissionMapForUser($user, $gate);

        $this->assertTrue($map['sales.create'] ?? false);
        $this->assertTrue($map['mobile_sales.dashboard.view'] ?? false);
        $this->assertTrue($map['mobile_sales.orders.create'] ?? false);
        $this->assertTrue($map['mobile_sales.catalog.view'] ?? false);
    }

    public function test_role_sync_preserves_permissions_for_disabled_modules(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'admin' => true,
                'sales' => true,
                'sales.backend' => true,
                'accounting' => false,
            ],
        ]);

        Sanctum::actingAs($admin);

        $role = \App\Models\Role::query()->firstOrFail();
        $accountingPerm = Permission::where('permission_code', 'accounting.chart_of_accounts.view')->firstOrFail();
        $salesPerm = Permission::where('permission_code', 'sales.orders.view')->firstOrFail();

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $accountingPerm->id],
            ['role_id' => $role->id, 'permission_id' => $salesPerm->id],
        ]);

        // UI/API only expose permissions for enabled modules.
        $this->getJson("/api/v1/roles/{$role->id}/permissions")
            ->assertOk()
            ->assertJsonPath('permission_ids', [$salesPerm->id]);

        // Saving the visible set must not wipe hidden (disabled-module) assignments.
        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [$salesPerm->id],
        ])
            ->assertOk()
            ->assertJsonPath('permission_ids', [$salesPerm->id]);

        $storedIds = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->pluck('permission_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            collect([$accountingPerm->id, $salesPerm->id])->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $storedIds,
        );

        // Client cannot newly assign disabled-module permissions either.
        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [$salesPerm->id, $accountingPerm->id],
        ])
            ->assertOk()
            ->assertJsonPath('permission_ids', [$salesPerm->id]);

        $this->assertTrue(
            DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->where('permission_id', $accountingPerm->id)
                ->exists(),
        );
    }

    public function test_role_sync_can_clear_visible_permissions_without_wiping_hidden(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'admin' => true,
                'sales' => true,
                'sales.backend' => true,
                'accounting' => false,
            ],
        ]);

        Sanctum::actingAs($admin);

        $role = \App\Models\Role::create([
            'role_name' => 'Preserve Hidden Role',
            'scope' => 'branch',
            'organization_id' => $org->id,
            'is_active' => true,
        ]);

        $accountingPerm = Permission::where('permission_code', 'accounting.chart_of_accounts.view')->firstOrFail();
        $salesPerm = Permission::where('permission_code', 'sales.orders.view')->firstOrFail();

        DB::table('role_permissions')->insert([
            ['role_id' => $role->id, 'permission_id' => $accountingPerm->id],
            ['role_id' => $role->id, 'permission_id' => $salesPerm->id],
        ]);

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [],
        ])
            ->assertOk()
            ->assertJsonPath('permission_ids', []);

        $this->assertSame(
            [(int) $accountingPerm->id],
            DB::table('role_permissions')
                ->where('role_id', $role->id)
                ->pluck('permission_id')
                ->map(fn ($id) => (int) $id)
                ->all(),
        );
    }
}
