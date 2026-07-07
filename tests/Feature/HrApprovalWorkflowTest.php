<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeLeaveDay;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HrApprovalWorkflowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function userWithPermissions(array $codes): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::query()->firstOrCreate(
            ['role_name' => 'HR Approval Test '.md5(json_encode($codes))],
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
            'username' => 'hr_approval_'.uniqid(),
            'email' => null,
            'password' => $admin->password,
            'full_name' => 'HR Approval Test',
            'is_admin' => false,
            'is_active' => true,
        ]);
    }

    protected function enablePayrollApproval(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $org = Organization::findOrFail($admin->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['hr_payroll'] = array_merge($settings['hr_payroll'] ?? [], [
            'require_payroll_approval' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_leave_request_creates_action_request_and_approver_can_resolve(): void
    {
        $hrClerk = $this->userWithPermissions(['hr.manage']);
        $approver = $this->userWithPermissions(['hr.leave.approve']);
        $employee = Employee::query()->firstOrFail();

        Sanctum::actingAs($hrClerk);

        $leave = $this->postJson('/api/v1/employee-leave-days', [
            'employee_id' => $employee->id,
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->toDateString(),
            'leave_type' => 'unpaid',
            'deduct_from' => 'unpaid',
            'notes' => 'Personal errand',
        ])->assertCreated()
            ->assertJsonPath('approval_status', 'pending')
            ->json();

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $hrClerk->organization_id,
            'type' => 'leave_request',
            'status' => 'pending',
            'reference_type' => 'employee_leave_day',
            'reference_id' => $leave['id'],
        ]);

        $requestId = DB::table('action_requests')
            ->where('type', 'leave_request')
            ->where('reference_id', $leave['id'])
            ->value('id');

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertSame(
            'approved',
            EmployeeLeaveDay::query()->findOrFail($leave['id'])->approval_status,
        );
    }

    public function test_cash_advance_creates_action_request_and_approver_can_open_advance(): void
    {
        $hrClerk = $this->userWithPermissions(['hr.manage']);
        $approver = $this->userWithPermissions(['hr.cash_advances.approve']);
        $employee = Employee::query()->firstOrFail();

        Sanctum::actingAs($hrClerk);

        $advance = $this->postJson('/api/v1/employee-cash-advances', [
            'employee_id' => $employee->id,
            'advance_date' => now()->toDateString(),
            'amount' => 1500,
            'notes' => 'Field float',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->json();

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $hrClerk->organization_id,
            'type' => 'cash_advance',
            'status' => 'pending',
            'reference_type' => 'employee_cash_advance',
            'reference_id' => $advance['id'],
        ]);

        $requestId = DB::table('action_requests')
            ->where('type', 'cash_advance')
            ->where('reference_id', $advance['id'])
            ->value('id');

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertSame(
            'open',
            EmployeeCashAdvance::query()->findOrFail($advance['id'])->status,
        );
    }

    public function test_payroll_run_with_approval_required_creates_action_request(): void
    {
        $this->enablePayrollApproval();
        $hrClerk = $this->userWithPermissions(['hr.manage', 'hr.payroll.create']);
        $approver = $this->userWithPermissions(['hr.payroll.approve']);
        $admin = User::where('username', 'admin')->firstOrFail();

        $period = PayPeriod::create([
            'organization_id' => $admin->organization_id,
            'period_code' => 'HR-APPR-'.uniqid(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'pay_date' => now()->endOfMonth()->toDateString(),
        ]);

        Sanctum::actingAs($hrClerk);

        $run = $this->postJson('/api/v1/payroll-runs', [
            'pay_period_id' => $period->id,
            'run_date' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('status', 'pending_approval')
            ->json();

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $hrClerk->organization_id,
            'type' => 'payroll_run',
            'status' => 'pending',
            'reference_type' => 'payroll_run',
            'reference_id' => $run['id'],
        ]);

        $requestId = DB::table('action_requests')
            ->where('type', 'payroll_run')
            ->where('reference_id', $run['id'])
            ->value('id');

        Sanctum::actingAs($approver);

        $this->postJson("/api/v1/action-requests/{$requestId}/approve")
            ->assertOk();

        $this->assertSame(
            'approved',
            PayrollRun::query()->findOrFail($run['id'])->status,
        );
    }
}
