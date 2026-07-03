<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_admin_can_sync_distinct_permissions_per_role(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $roleA = Role::query()->firstOrFail();
        $roleB = Role::query()->where('id', '!=', $roleA->id)->firstOrFail();

        $permA = Permission::where('permission_code', 'dashboard.overview.view')->firstOrFail();
        $permB = Permission::where('permission_code', 'catalogue.products.view')->firstOrFail();

        $this->putJson("/api/v1/roles/{$roleA->id}/permissions", [
            'permission_ids' => [$permA->id],
        ])->assertOk()->assertJsonPath('permission_ids', [$permA->id]);

        $this->putJson("/api/v1/roles/{$roleB->id}/permissions", [
            'permission_ids' => [$permB->id],
        ])->assertOk()->assertJsonPath('permission_ids', [$permB->id]);

        $this->getJson("/api/v1/roles/{$roleA->id}/permissions")
            ->assertOk()
            ->assertJsonPath('permission_ids', [$permA->id]);

        $this->getJson("/api/v1/roles/{$roleB->id}/permissions")
            ->assertOk()
            ->assertJsonPath('permission_ids', [$permB->id]);
    }

    public function test_invalid_role_id_returns_not_found(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/roles/undefined/permissions')->assertNotFound();
    }

    public function test_permission_matrix_groups_are_distinct(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/roles/permissions/matrix')->assertOk();
        $groups = collect($res->json('groups'));

        $this->assertTrue($groups->contains(fn ($g) => $g['module'] === 'dashboard'));
        $this->assertTrue($groups->contains(fn ($g) => $g['module'] === 'catalogue'));

        $dashboardIds = collect(
            $groups->firstWhere('module', 'dashboard')['features'][0]['permissions'] ?? [],
        )->pluck('id');

        $catalogueIds = collect(
            $groups->firstWhere('module', 'catalogue')['features'][0]['permissions'] ?? [],
        )->pluck('id');

        $this->assertFalse($dashboardIds->intersect($catalogueIds)->isNotEmpty());
    }

    public function test_permission_matrix_applications_group_modules_for_ui(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $res = $this->getJson('/api/v1/roles/permissions/matrix')->assertOk();
        $applications = collect($res->json('applications'));

        $this->assertSame(
            ['pos', 'mobile', 'backoffice', 'accounting', 'hr', 'distribution', 'admin'],
            $applications->pluck('id')->all(),
        );

        $accounting = $applications->firstWhere('id', 'accounting');
        $this->assertSame('Accounting', $accounting['label']);

        $moduleKeys = collect($accounting['modules'])->pluck('module')->all();
        $this->assertContains('accounting', $moduleKeys);
        $this->assertContains('payments', $moduleKeys);

        $externalErp = $applications->firstWhere('id', 'pos');
        $this->assertNotNull($externalErp);
        $this->assertSame('External ERP', $externalErp['label']);
        $this->assertSame(['pos'], collect($externalErp['modules'])->pluck('module')->all());

        $mobile = $applications->firstWhere('id', 'mobile');
        $this->assertNotNull($mobile);
        $this->assertSame('Mobile application', $mobile['label']);
        $this->assertTrue($mobile['standalone']);
        $this->assertSame(['mobile_sales', 'mobile_driver'], collect($mobile['modules'])->pluck('module')->all());
    }

    public function test_clearing_role_permissions_persists_empty_set(): void
    {
        PermissionMatrixService::ensure();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $role = Role::query()->firstOrFail();
        $this->assertGreaterThan(0, DB::table('role_permissions')->where('role_id', $role->id)->count());

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [],
        ])
            ->assertOk()
            ->assertJsonPath('permission_ids', []);

        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
    }
}
