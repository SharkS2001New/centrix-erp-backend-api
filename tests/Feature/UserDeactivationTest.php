<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserDeactivationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_delete_user_without_activity_removes_record(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $target = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'cashier_old',
            'password' => Hash::make('password'),
            'full_name' => 'Old Cashier',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $this->deleteJson("/api/v1/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('mode', 'deleted')
            ->assertJsonFragment(['message' => 'User permanently deleted.']);

        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_deactivated_user_cannot_log_in(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        User::create([
            'organization_id' => $org->id,
            'branch_id' => 1,
            'role_id' => 1,
            'username' => 'inactive_user',
            'password' => Hash::make('password'),
            'full_name' => 'Inactive User',
            'access_scope' => 'branch',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'inactive_user',
            'password' => 'password',
            'client_id' => 'PC_INACTIVE',
        ])->assertStatus(422);
    }

    public function test_deactivated_user_token_is_rejected(): void
    {
        $user = User::create([
            'organization_id' => 1,
            'branch_id' => 1,
            'role_id' => 1,
            'username' => 'token_user',
            'password' => Hash::make('password'),
            'full_name' => 'Token User',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $token = $user->createToken('test-client');
        $user->forceFill(['is_active' => false])->save();

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(403)
            ->assertJsonFragment(['code' => 'account_inactive']);
    }

    public function test_inactive_employee_disables_linked_user_login(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $linkedUser = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'emp_user',
            'password' => Hash::make('password'),
            'full_name' => 'Employee User',
            'access_scope' => 'branch',
            'is_active' => true,
        ]);

        $employee = Employee::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'user_id' => $linkedUser->id,
            'employee_code' => 'EMP-SYNC-1',
            'payroll_number' => 'EMP-SYNC-1',
            'first_name' => 'Employee',
            'last_name' => 'User',
            'full_name' => 'Employee User',
            'employment_status' => 'active',
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/employees/{$employee->id}", [
            'employment_status' => 'terminated',
        ])->assertOk();

        $linkedUser->refresh();
        $this->assertFalse($linkedUser->is_active);
        $this->assertSame(0, $linkedUser->tokens()->count());
    }

    public function test_cannot_enable_login_while_linked_employee_is_inactive(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $linkedUser = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'blocked_user',
            'password' => Hash::make('password'),
            'full_name' => 'Blocked User',
            'access_scope' => 'branch',
            'is_active' => false,
        ]);

        Employee::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'user_id' => $linkedUser->id,
            'employee_code' => 'EMP-BLOCK-1',
            'payroll_number' => 'EMP-BLOCK-1',
            'first_name' => 'Blocked',
            'last_name' => 'User',
            'full_name' => 'Blocked User',
            'employment_status' => 'terminated',
            'is_active' => false,
        ]);

        $this->putJson("/api/v1/users/{$linkedUser->id}", [
            'is_active' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['is_active']);
    }
}
