<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Models\Employee;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthRegistrationConcurrencyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_admin_can_provision_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $payload = [
            'company_code' => 'NEWORG',
            'org_name' => 'New Organization Ltd',
            'org_email' => 'contact@neworg.com',
            'primary_tel' => '0711222333',
            'org_address' => 'Nairobi, KE',
            'deployment_profile' => 'small_shop',
            'admin_username' => 'admin_user',
            'admin_email' => 'admin@neworg.com',
            'admin_password' => 'supersecretpassword',
            'admin_full_name' => 'Main Admin',
        ];

        $response = $this->postJson('/api/v1/admin/organizations/provision', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure(['organization', 'manager', 'branch', 'message'])
            ->assertJsonMissing(['token']);

        $this->assertDatabaseHas('organizations', [
            'company_code' => 'NEWORG',
            'org_name' => 'New Organization Ltd',
        ]);

        $org = Organization::where('company_code', 'NEWORG')->firstOrFail();

        $this->assertDatabaseHas('branches', [
            'organization_id' => $org->id,
            'branch_code' => 'HQ',
            'branch_type' => 'small_shop',
        ]);

        $branch = Branch::where('organization_id', $org->id)->where('branch_code', 'HQ')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'username' => 'admin_user',
            'email' => 'admin@neworg.com',
            'is_admin' => 1,
        ]);

        $user = User::where('organization_id', $org->id)->where('username', 'admin_user')->firstOrFail();

        $this->assertDatabaseHas('employees', [
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'email' => 'admin@neworg.com',
        ]);
    }

    public function test_provision_requires_admin_and_feature_flag(): void
    {
        config(['erp.allow_org_provisioning' => false]);

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'BLOCKED',
            'org_name' => 'Blocked Org',
            'org_email' => 'blocked@test.com',
            'primary_tel' => '0700000000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'admin_username' => 'mgr',
            'admin_email' => 'mgr@test.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Manager',
        ])->assertStatus(403);

        config(['erp.allow_org_provisioning' => true]);

        $cashier = User::create([
            'organization_id' => $admin->organization_id,
            'role_id' => 1,
            'username' => 'cashier_only',
            'password' => Hash::make('password123'),
            'full_name' => 'Cashier Only',
            'is_active' => true,
            'is_admin' => false,
        ]);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'BLOCKED2',
            'org_name' => 'Blocked Org 2',
            'org_email' => 'blocked2@test.com',
            'primary_tel' => '0700000001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'admin_username' => 'mgr2',
            'admin_email' => 'mgr2@test.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Manager Two',
        ])->assertStatus(403);
    }

    public function test_scoped_username_uniqueness(): void
    {
        // Setup ORG1 and user 'employee1'
        $org1 = Organization::create([
            'company_code' => 'ORG1',
            'org_name' => 'First Org',
            'org_email' => 'org1@test.com',
            'primary_tel' => '111',
            'org_address' => 'Addr 1',
        ]);
        User::create([
            'organization_id' => $org1->id,
            'role_id' => 1,
            'username' => 'employee1',
            'password' => Hash::make('password123'),
            'full_name' => 'Employee One',
            'is_active' => true,
        ]);

        // Setup ORG2 and user 'employee1' (should not clash)
        $org2 = Organization::create([
            'company_code' => 'ORG2',
            'org_name' => 'Second Org',
            'org_email' => 'org2@test.com',
            'primary_tel' => '222',
            'org_address' => 'Addr 2',
        ]);
        User::create([
            'organization_id' => $org2->id,
            'role_id' => 1,
            'username' => 'employee1',
            'password' => Hash::make('password456'),
            'full_name' => 'Employee Two',
            'is_active' => true,
        ]);

        // Verify both can log in using their respective company codes
        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'ORG1',
            'username' => 'employee1',
            'password' => 'password123',
            'client_id' => 'PC1',
        ])->assertOk()->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'ORG2',
            'username' => 'employee1',
            'password' => 'password456',
            'client_id' => 'PC2',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_blocks_second_device_while_first_is_active(): void
    {
        $org = Organization::create([
            'company_code' => 'TESTCONC',
            'org_name' => 'Testing Concurrency',
            'org_email' => 'test@conc.com',
            'primary_tel' => '999',
            'org_address' => 'Addr',
        ]);
        $user = User::create([
            'organization_id' => $org->id,
            'role_id' => 1,
            'username' => 'cashier_user',
            'password' => Hash::make('secret123'),
            'full_name' => 'Cashier User',
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'TESTCONC',
            'username' => 'cashier_user',
            'password' => 'secret123',
            'client_id' => 'PC1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'TESTCONC',
            'username' => 'cashier_user',
            'password' => 'secret123',
            'client_id' => 'PC2',
        ])->assertStatus(403)
            ->assertJsonFragment([
                'message' => 'This user is already logged in on another device.',
                'code' => 'session_active_elsewhere',
            ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'TESTCONC',
            'username' => 'cashier_user',
            'password' => 'secret123',
            'client_id' => 'PC2',
            'force_logout' => true,
        ])->assertOk();
    }

    public function test_login_prunes_abandoned_unused_token(): void
    {
        config(['erp.session_idle_minutes' => 15]);

        $org = Organization::create([
            'company_code' => 'TESTABN',
            'org_name' => 'Abandoned Token Org',
            'org_email' => 'abn@test.com',
            'primary_tel' => '999',
            'org_address' => 'Addr',
        ]);
        $user = User::create([
            'organization_id' => $org->id,
            'role_id' => 1,
            'username' => 'solo_user',
            'password' => Hash::make('secret123'),
            'full_name' => 'Solo User',
            'is_active' => true,
        ]);

        $token = $user->createToken('OLD_BROWSER');
        \Illuminate\Support\Facades\DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['created_at' => now()->subMinutes(6), 'last_used_at' => null]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'TESTABN',
            'username' => 'solo_user',
            'password' => 'secret123',
            'client_id' => 'NEW_BROWSER',
        ])->assertOk();
    }

    public function test_login_requires_company_code(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'password',
            'client_id' => 'PC_SETUP',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['company_code']);
    }

    public function test_login_with_company_code(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'password' => 'password',
            'client_id' => 'PC_SETUP',
        ])->assertOk()->assertJsonStructure(['token', 'user', 'organization', 'memberships']);
    }
}
