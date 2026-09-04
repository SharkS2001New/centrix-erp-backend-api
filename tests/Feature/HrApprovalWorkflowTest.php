<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeCashAdvance;
use App\Models\EmployeeLeaveDay;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\PlatformSubscription;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HrApprovalWorkflowTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::where('username', 'admin')->first();
        if ($admin) {
            $this->ensureActiveSubscription($admin);
        }
    }

    protected function ensureActiveSubscription(User $user): void
    {
        if (! $user->organization_id) {
            return;
        }

        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );
    }

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
            // Client cannot skip approval by sending open.
            'status' => 'open',
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

    public function test_cash_advance_assigns_line_manager_when_manager_has_approve_right(): void
    {
        $hrClerk = $this->userWithPermissions(['hr.manage']);
        $managerUser = $this->userWithPermissions(['hr.cash_advances.approve']);
        $employee = Employee::query()->firstOrFail();

        $managerEmployee = $employee->replicate();
        $managerEmployee->employee_code = 'MGR-CA-'.uniqid();
        $managerEmployee->payroll_number = 'MGR-CA-'.uniqid();
        $managerEmployee->first_name = 'Approve';
        $managerEmployee->last_name = 'Manager';
        $managerEmployee->full_name = 'Approve Manager';
        $managerEmployee->user_id = $managerUser->id;
        $managerEmployee->reports_to_employee_id = null;
        $managerEmployee->save();

        $employee->forceFill(['reports_to_employee_id' => $managerEmployee->id])->save();

        Sanctum::actingAs($hrClerk);

        $advance = $this->postJson('/api/v1/employee-cash-advances', [
            'employee_id' => $employee->id,
            'advance_date' => now()->toDateString(),
            'amount' => 900,
            'notes' => 'Assigned manager path',
        ])->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->json();

        $this->assertDatabaseHas('action_requests', [
            'type' => 'cash_advance',
            'reference_id' => $advance['id'],
            'assigned_to' => $managerUser->id,
            'status' => 'pending',
        ]);
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

    public function test_lateness_waiver_notifies_capability_approvers_and_skips_unqualified_manager(): void
    {
        // Requester can view attendance but cannot approve waivers → must notify.
        $requester = $this->userWithPermissions(['hr.attendance.view']);
        $approver = $this->userWithPermissions(['hr.attendance_waive.approve']);
        $unqualifiedManager = $this->userWithPermissions(['hr.attendance.view']);
        $admin = User::where('username', 'admin')->firstOrFail();
        $employee = Employee::query()->firstOrFail();

        $managerEmployee = $employee->replicate();
        $managerEmployee->employee_code = 'MGR-WV-'.uniqid();
        $managerEmployee->payroll_number = 'MGR-WV-'.uniqid();
        $managerEmployee->first_name = 'No';
        $managerEmployee->last_name = 'Approve';
        $managerEmployee->full_name = 'No Approve';
        $managerEmployee->user_id = $unqualifiedManager->id;
        $managerEmployee->reports_to_employee_id = null;
        $managerEmployee->save();
        $employee->forceFill(['reports_to_employee_id' => $managerEmployee->id])->save();

        $attendance = \App\Models\EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => now()->subDay()->toDateString(),
            'check_in' => '09:00:00',
            'check_out' => '17:00:00',
            'status' => 'late',
            'source' => 'manual',
            'hours_worked' => 7.5,
            'expected_hours' => 8,
            'late_minutes' => 30,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $waiver = app(\App\Services\Hr\LatenessWaiverApprovalService::class)->submit(
            $requester,
            $attendance,
            true,
            'Traffic',
        );

        $this->assertSame('pending', $waiver->status);
        $this->assertNull($waiver->assigned_manager_user_id);
        $this->assertDatabaseHas('action_requests', [
            'type' => 'lateness_waiver',
            'reference_type' => 'lateness_waiver_request',
            'reference_id' => $waiver->id,
            'status' => 'pending',
            'assigned_to' => null,
        ]);

        $actionRequestId = (int) DB::table('action_requests')
            ->where('type', 'lateness_waiver')
            ->where('reference_id', $waiver->id)
            ->value('id');

        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $approver->id,
            'action_request_id' => $actionRequestId,
            'type' => 'approval',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $unqualifiedManager->id,
            'action_request_id' => $actionRequestId,
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'user_id' => $requester->id,
            'action_request_id' => $actionRequestId,
        ]);

        $this->assertStringContainsString(
            '/hr/attendance/history?',
            (string) DB::table('in_app_notifications')
                ->where('action_request_id', $actionRequestId)
                ->value('action_url'),
        );
    }

    public function test_lateness_waiver_auto_approves_when_requester_can_approve(): void
    {
        $approver = $this->userWithPermissions(['hr.attendance_waive.approve']);
        $admin = User::where('username', 'admin')->firstOrFail();
        $employee = Employee::query()->firstOrFail();

        $attendance = \App\Models\EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => now()->subDays(2)->toDateString(),
            'check_in' => '09:15:00',
            'check_out' => '17:00:00',
            'status' => 'late',
            'source' => 'manual',
            'hours_worked' => 7.25,
            'expected_hours' => 8,
            'late_minutes' => 45,
            'lunch_late_minutes' => 0,
            'lateness_waived' => false,
        ]);

        $waiver = app(\App\Services\Hr\LatenessWaiverApprovalService::class)->submit(
            $approver,
            $attendance,
            true,
            'Manager on site',
        );

        $this->assertSame('approved', $waiver->status);
        $this->assertTrue((bool) $attendance->fresh()->lateness_waived);
        $this->assertDatabaseMissing('action_requests', [
            'type' => 'lateness_waiver',
            'reference_id' => $waiver->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('in_app_notifications', [
            'type' => 'approval',
            'title' => 'Lateness waiver needs approval',
        ]);
    }
}
