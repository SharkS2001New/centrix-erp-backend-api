<?php

namespace Tests\Feature;

use App\Models\Organization;
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

    protected function actingAsLicensedAdmin(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        \App\Models\PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $admin->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
        Sanctum::actingAs($admin);

        return $admin;
    }

    public function test_admin_can_sync_distinct_permissions_per_role(): void
    {
        PermissionMatrixService::ensure();
        app(\App\Services\Auth\RoleTemplateService::class)->ensureAllRoles();

        $this->actingAsLicensedAdmin();

        // Avoid Administrator — ensure() helpers re-attach admin-only codes on that role.
        $roleA = Role::query()->where('role_name', 'Cashier')->firstOrFail();
        $roleB = Role::query()->where('role_name', 'Warehouse Clerk')->firstOrFail();

        // Avoid dashboard.overview.view — ensureNotificationsForBackofficeRoles re-attaches extras.
        $permA = Permission::where('permission_code', 'pos.terminal.view')->firstOrFail();
        $permB = Permission::where('permission_code', 'catalogue.products.view')->firstOrFail();

        $this->putJson("/api/v1/roles/{$roleA->id}/permissions", [
            'permission_ids' => [$permA->id],
        ])->assertOk();
        $this->assertSame(
            [$permA->id],
            $this->getJson("/api/v1/roles/{$roleA->id}/permissions")->assertOk()->json('permission_ids'),
        );

        $this->putJson("/api/v1/roles/{$roleB->id}/permissions", [
            'permission_ids' => [$permB->id],
        ])->assertOk();
        $this->assertSame(
            [$permB->id],
            $this->getJson("/api/v1/roles/{$roleB->id}/permissions")->assertOk()->json('permission_ids'),
        );
    }

    public function test_invalid_role_id_returns_not_found(): void
    {
        $this->actingAsLicensedAdmin();

        $this->getJson('/api/v1/roles/undefined/permissions')->assertNotFound();
    }

    public function test_permission_matrix_groups_are_distinct(): void
    {
        $this->actingAsLicensedAdmin();

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
        $this->actingAsLicensedAdmin();

        $res = $this->getJson('/api/v1/roles/permissions/matrix')->assertOk();
        $applications = collect($res->json('applications'));

        $this->assertSame(
            ['pos', 'mobile', 'manager', 'backoffice', 'accounting', 'hr', 'distribution', 'admin'],
            $applications->pluck('id')->all(),
        );
        $this->assertFalse($applications->contains(fn (array $app) => ($app['id'] ?? '') === 'hotel_bar_pos'));
        $this->assertFalse($applications->contains(fn (array $app) => ($app['id'] ?? '') === 'hospitality_backoffice'));
        $this->assertSame('commerce', $res->json('industry'));

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
        app(\App\Services\Auth\RoleTemplateService::class)->ensureAllRoles();

        $this->actingAsLicensedAdmin();

        $role = Role::query()->where('role_name', 'Cashier')->firstOrFail();
        $this->assertGreaterThan(0, DB::table('role_permissions')->where('role_id', $role->id)->count());

        $this->putJson("/api/v1/roles/{$role->id}/permissions", [
            'permission_ids' => [],
        ])
            ->assertOk()
            ->assertJsonPath('permission_ids', []);

        $this->assertSame(0, DB::table('role_permissions')->where('role_id', $role->id)->count());
    }

    public function test_platform_admin_permission_matrix_uses_acting_organization_modules(): void
    {
        config(['erp.allow_org_provisioning' => true]);
        PermissionMatrixService::ensure();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $platformOrg = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();
        if ($platformOrg) {
            // Platform shell typically has few operational modules; AI can still appear via settings.
            $platformOrg->update([
                'enabled_modules' => ['admin' => true],
            ]);
        }

        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'PERMORG',
            'org_name' => 'Permission Matrix Org',
            'org_email' => 'perm@org.com',
            'primary_tel' => '0711000099',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'inventory' => true,
                'customers_suppliers' => true,
                'admin' => true,
            ],
            'admin_username' => 'perm_admin',
            'admin_email' => 'perm@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Perm Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $groups = collect(
            $this->getJson("/api/v1/admin/organizations/{$orgId}/roles/permissions/matrix")
                ->assertOk()
                ->json('groups'),
        )->pluck('module');

        $this->assertTrue($groups->contains('sales'));
        $this->assertTrue($groups->contains('inventory'));
        $this->assertTrue($groups->contains('catalogue'));
        $this->assertTrue($groups->contains('customers'));
        $this->assertTrue($groups->contains('purchasing'));
    }

    public function test_hospitality_permission_matrix_excludes_commerce_apps_and_groups(): void
    {
        config(['erp.allow_org_provisioning' => true]);
        PermissionMatrixService::ensure();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'HOTELMTX',
            'org_name' => 'Hotel Matrix Org',
            'org_email' => 'hotelmtx@org.com',
            'primary_tel' => '0711000077',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
            'admin_username' => 'hotel_mtx_admin',
            'admin_email' => 'hotelmtx@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Hotel Matrix Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $matrix = $this->getJson("/api/v1/admin/organizations/{$orgId}/roles/permissions/matrix")
            ->assertOk();

        $this->assertSame('hospitality', $matrix->json('industry'));

        $appIds = collect($matrix->json('applications'))->pluck('id');
        $this->assertTrue($appIds->contains('hotel_bar_pos'));
        $this->assertTrue($appIds->contains('hospitality_backoffice'));
        $this->assertFalse($appIds->contains('pos'));
        $this->assertFalse($appIds->contains('backoffice'));
        $this->assertFalse($appIds->contains('distribution'));
        $this->assertFalse($appIds->contains('mobile'));
        $this->assertFalse($appIds->contains('manager'));

        $groupModules = collect($matrix->json('groups'))->pluck('module');
        $this->assertTrue($groupModules->contains('hospitality'));
        $this->assertTrue($groupModules->contains('hotel_bar_pos'));
        $this->assertFalse($groupModules->contains('pos'));
        $this->assertFalse($groupModules->contains('sales'));
        $this->assertFalse($groupModules->contains('mobile_sales'));
        $this->assertFalse($groupModules->contains('fulfillment'));

        $permissionCodes = collect($matrix->json('permissions'))->pluck('permission_code');
        $this->assertFalse($permissionCodes->contains(fn ($code) => str_starts_with((string) $code, 'pos.')));
        $this->assertFalse($permissionCodes->contains(fn ($code) => str_starts_with((string) $code, 'mobile_sales.')));
    }

    public function test_hospitality_roles_index_hides_commerce_templates(): void
    {
        config(['erp.allow_org_provisioning' => true]);
        PermissionMatrixService::ensure();
        app(\App\Services\Auth\RoleTemplateService::class)->ensureAllRoles();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'HOTELROL',
            'org_name' => 'Hotel Roles Org',
            'org_email' => 'hotelrol@org.com',
            'primary_tel' => '0711000066',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'hotel_bar',
            'admin_username' => 'hotel_rol_admin',
            'admin_email' => 'hotelrol@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Hotel Roles Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $names = collect(
            $this->getJson("/api/v1/admin/organizations/{$orgId}/roles?per_page=200")
                ->assertOk()
                ->json('data'),
        )->pluck('role_name');

        $this->assertTrue($names->contains('Hotel Cashier'));
        $this->assertTrue($names->contains('Front Desk'));
        $this->assertTrue($names->contains('Hotel Manager'));
        $this->assertFalse($names->contains('Cashier'));
        $this->assertFalse($names->contains('Driver'));
        $this->assertFalse($names->contains('Mobile Sales Rep'));
        $this->assertFalse($names->contains('Dispatch Coordinator'));
    }

    public function test_platform_admin_acting_as_still_sees_admin_permissions_when_admin_module_disabled(): void
    {
        config(['erp.allow_org_provisioning' => true]);
        PermissionMatrixService::ensure();

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'NOADMIN',
            'org_name' => 'No Admin Module Org',
            'org_email' => 'noadmin@org.com',
            'primary_tel' => '0711000088',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'sales' => true,
                'sales.backend' => true,
                'inventory' => true,
                'customers_suppliers' => true,
                'admin' => false,
            ],
            'admin_username' => 'noadmin_user',
            'admin_email' => 'noadmin@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'No Admin User',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $matrix = $this->getJson("/api/v1/admin/organizations/{$orgId}/roles/permissions/matrix")
            ->assertOk();

        $groups = collect($matrix->json('groups'))->pluck('module');
        $applications = collect($matrix->json('applications'))->pluck('id');

        $this->assertTrue($groups->contains('admin'), 'Administration permissions must remain visible while acting as');
        $this->assertTrue($applications->contains('admin'));
        $this->assertTrue($groups->contains('sales'));
    }
}
