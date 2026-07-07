<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Erp\PermissionMatrixService;
use App\Services\Notifications\ActionRequestService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ExpenseApprovalTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function orgAdminShell(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        PermissionMatrixService::ensure();

        $role = Role::create([
            'role_name' => 'Expense Approval Shell '.uniqid(),
            'scope' => 'org',
            'is_active' => true,
        ]);

        return User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'expense_shell_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Expense Shell Admin',
            'access_scope' => 'org',
            'is_admin' => true,
            'is_active' => true,
        ]);
    }

    public function test_org_admin_without_accounting_role_submits_expense_for_approval(): void
    {
        $user = $this->orgAdminShell();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/expenses', [
            'branch_id' => $user->branch_id,
            'expense_group_id' => 1,
            'description' => 'Field petty cash',
            'expense_amount' => 350,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => 1,
        ])
            ->assertStatus(202)
            ->assertJsonPath('pending_approval', true);

        $this->assertDatabaseHas('action_requests', [
            'organization_id' => $user->organization_id,
            'type' => 'expense_action',
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        $this->assertDatabaseMissing('expenses', [
            'description' => 'Field petty cash',
            'expense_amount' => 350,
        ]);
    }

    public function test_accounting_manager_can_create_expense_directly(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/expenses', [
            'branch_id' => $admin->branch_id,
            'expense_group_id' => 1,
            'description' => 'Direct office expense',
            'expense_amount' => 120,
            'expense_date' => now()->toDateString(),
            'payment_method_id' => 1,
        ])
            ->assertCreated()
            ->assertJsonMissingPath('pending_approval');

        $this->assertDatabaseHas('expenses', [
            'description' => 'Direct office expense',
            'expense_amount' => 120,
        ]);
    }

    public function test_accounting_manager_can_approve_pending_expense_action_request(): void
    {
        $requester = $this->orgAdminShell();
        $manager = User::where('username', 'admin')->firstOrFail();

        $actionRequest = app(ActionRequestService::class)->requestApproval($requester, [
            'type' => 'expense_action',
            'module' => 'accounting',
            'reference_type' => 'expense',
            'reference_id' => 0,
            'approver_permission' => 'accounting.manage',
            'title' => 'Expense approval required',
            'message' => 'Petty cash reimbursement',
            'reason' => 'Courier fees',
            'severity' => 'warning',
            'action_url' => '/expenses',
            'allow_duplicate_reference' => true,
            'payload' => [
                'action' => 'create',
                'data' => [
                    'branch_id' => $requester->branch_id,
                    'expense_group_id' => 1,
                    'description' => 'Courier fees',
                    'expense_amount' => 275,
                    'expense_date' => now()->toDateString(),
                    'payment_method_id' => 1,
                ],
                'action_url' => '/expenses',
            ],
        ]);

        Sanctum::actingAs($manager);

        $this->postJson("/api/v1/action-requests/{$actionRequest->id}/approve")
            ->assertOk();

        $this->assertDatabaseHas('expenses', [
            'description' => 'Courier fees',
            'expense_amount' => 275,
        ]);

        $this->assertDatabaseHas('action_requests', [
            'id' => $actionRequest->id,
            'status' => 'approved',
        ]);
    }
}
