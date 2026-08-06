<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class DashboardAnalyticsPermissionIndependenceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_business_summary_permission_does_not_grant_sales_analytics(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = $this->makeUserWithPermissions($admin, [
            'dashboard.overview.view',
        ]);

        $gate = app(ErpContext::class)->gateForUser($user);
        $svc = app(UserPermissionService::class);
        $map = $svc->permissionMapForUser($user, $gate);
        $nav = $svc->navigationPermissionMapForUser($user, $gate);

        $this->assertTrue($map['dashboard.overview.view'] ?? false);
        $this->assertFalse($map['dashboard.sales.view'] ?? false);
        $this->assertFalse($map['sales.dashboard.view'] ?? false);

        $this->assertTrue($nav['dashboard.overview.view'] ?? false);
        $this->assertFalse($nav['dashboard.sales.view'] ?? false);
        $this->assertFalse($nav['sales.dashboard.view'] ?? false);
    }

    public function test_sales_analytics_permission_does_not_grant_business_summary(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $user = $this->makeUserWithPermissions($admin, [
            'dashboard.sales.view',
        ]);

        $gate = app(ErpContext::class)->gateForUser($user);
        $svc = app(UserPermissionService::class);
        $map = $svc->permissionMapForUser($user, $gate);
        $nav = $svc->navigationPermissionMapForUser($user, $gate);

        $this->assertTrue($map['dashboard.sales.view'] ?? false);
        $this->assertTrue($map['sales.dashboard.view'] ?? false);
        $this->assertFalse($map['dashboard.overview.view'] ?? false);

        $this->assertTrue($nav['dashboard.sales.view'] ?? false);
        $this->assertFalse($nav['dashboard.overview.view'] ?? false);
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    protected function makeUserWithPermissions(User $template, array $permissionCodes): User
    {
        $role = Role::create([
            'role_name' => 'Dashboard Analytics '.uniqid(),
            'scope' => 'branch',
            'is_active' => true,
        ]);

        foreach ($permissionCodes as $code) {
            $permissionId = (int) Permission::where('permission_code', $code)->value('id');
            $this->assertGreaterThan(0, $permissionId, "Missing permission {$code}");
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => $permissionId,
            ]);
        }

        return User::create([
            'organization_id' => $template->organization_id,
            'branch_id' => $template->branch_id,
            'role_id' => $role->id,
            'username' => 'dash_analytics_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Dashboard Analytics User',
            'access_scope' => 'branch',
            'is_active' => true,
            'is_admin' => false,
            'login_channels' => ['backoffice'],
        ]);
    }
}
