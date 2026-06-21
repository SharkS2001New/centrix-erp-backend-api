<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
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

    public function test_role_sync_rejects_permissions_for_disabled_modules(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::query()->findOrFail((int) $admin->organization_id);
        $org->update([
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'accounting' => false,
            ],
        ]);

        Sanctum::actingAs($admin);

        $role = \App\Models\Role::query()->firstOrFail();
        $accountingPerm = Permission::where('permission_code', 'accounting.chart_of_accounts.view')->firstOrFail();

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [$accountingPerm->id],
        ])->assertUnprocessable();
    }
}
