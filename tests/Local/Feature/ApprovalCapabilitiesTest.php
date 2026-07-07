<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\PermissionMatrixService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ApprovalCapabilitiesTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'Approval Cap Test '.md5(json_encode($codes))],
            ['scope' => 'branch', 'is_active' => true],
        );

        $permissionIds = Permission::query()
            ->whereIn('permission_code', $codes)
            ->pluck('id')
            ->all();

        DB::table('role_permissions')->where('role_id', $role->id)->delete();
        foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->insert([
                'role_id' => $role->id,
                'permission_id' => (int) $permissionId,
            ]);
        }

        return User::query()->create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'approval_cap_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'Approval Cap Test',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    public function test_capabilities_include_approval_permissions_map(): void
    {
        $user = $this->userWithPermissions(['hr.leave.approve']);
        $this->actingAs($user, 'sanctum');

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('approval_permissions.leave_requests', true)
            ->assertJsonPath('approval_permissions.payroll_runs', false);
    }

    public function test_org_admin_without_role_permissions_cannot_approve_payroll(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Payroll Admin Shell',
            'scope' => 'org',
            'is_active' => true,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'payroll_admin_shell_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Payroll Admin Shell',
            'access_scope' => 'org',
            'is_admin' => true,
            'is_active' => true,
        ]);

        $service = app(UserPermissionService::class);
        $this->assertFalse($service->canApprovePayrollRuns($user->fresh()));
    }

    public function test_hr_manage_alias_grants_leave_approval_capability(): void
    {
        $user = $this->userWithPermissions(['hr.employees.edit']);
        $service = app(UserPermissionService::class);

        $this->assertTrue($service->canApproveLeaveRequests($user->fresh()));
    }
}
