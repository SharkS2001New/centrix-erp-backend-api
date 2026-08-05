<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Erp\PermissionMatrixService;
use App\Services\Erp\WorkspaceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class WorkspacePermissionGateTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionMatrixService::ensure();
    }

    public function test_sale_payment_permissions_do_not_unlock_accounting_workspace(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['accounting'] = true;
        $modules['payments'] = true;
        $org->update(['enabled_modules' => $modules]);

        $user = $this->makeUserWithPermissions($admin, [
            'payments.sale_payments.view',
            'payments.sale_payments.create',
            'sales.orders.view',
            'catalogue.products.view',
        ]);

        $ids = $this->workspaceIdsFor($user);

        $this->assertContains('backoffice', $ids);
        $this->assertNotContains('accounting', $ids);
        $this->assertNotContains('hr', $ids);
    }

    public function test_accounting_dashboard_permission_unlocks_accounting_workspace(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['accounting'] = true;
        $org->update(['enabled_modules' => $modules]);

        $user = $this->makeUserWithPermissions($admin, [
            'accounting.dashboard.view',
        ]);

        $ids = $this->workspaceIdsFor($user);

        $this->assertContains('accounting', $ids);
    }

    public function test_user_without_hr_permissions_does_not_see_hr_workspace(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['hr_payroll'] = true;
        $modules['accounting'] = true;
        $org->update(['enabled_modules' => $modules]);

        $user = $this->makeUserWithPermissions($admin, [
            'sales.orders.view',
            'catalogue.products.view',
        ]);

        $ids = $this->workspaceIdsFor($user);

        $this->assertNotContains('hr', $ids);
        $this->assertNotContains('accounting', $ids);
    }

    public function test_hr_permission_unlocks_hr_workspace(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $modules = is_array($org->enabled_modules) ? $org->enabled_modules : [];
        $modules['hr_payroll'] = true;
        $org->update(['enabled_modules' => $modules]);

        $user = $this->makeUserWithPermissions($admin, [
            'hr.employees.view',
        ]);

        $ids = $this->workspaceIdsFor($user);

        $this->assertContains('hr', $ids);
    }

    /**
     * @param  list<string>  $permissionCodes
     */
    protected function makeUserWithPermissions(User $template, array $permissionCodes): User
    {
        $role = Role::create([
            'role_name' => 'Workspace Gate '.uniqid(),
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
            'username' => 'ws_gate_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Workspace Gate User',
            'access_scope' => 'branch',
            'is_active' => true,
            'is_admin' => false,
            'login_channels' => ['backoffice'],
        ]);
    }

    /** @return list<string> */
    protected function workspaceIdsFor(User $user): array
    {
        $gate = app(ErpContext::class)->gateForUser($user);

        return array_column(
            app(WorkspaceResolver::class)->availableForUser($user, $gate),
            'id',
        );
    }
}
