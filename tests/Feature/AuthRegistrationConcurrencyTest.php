<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthRegistrationConcurrencyTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_provision_organization(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

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

        $this->assertDatabaseHas('users', [
            'username' => 'admin_user',
            'is_admin' => 1,
            'is_super_admin' => 0,
        ]);
    }

    public function test_super_admin_can_update_organization_modules(): void
    {
        config(['erp.allow_org_provisioning' => true]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'MODORG',
            'org_name' => 'Module Org Ltd',
            'org_email' => 'mod@org.com',
            'primary_tel' => '0711000000',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => ['sales' => true, 'sales.pos' => true, 'sales.reports' => false, 'hr_payroll' => true],
            'admin_username' => 'mod_admin',
            'admin_email' => 'mod@org.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Mod Admin',
        ])->assertCreated();

        $orgId = $create->json('organization.id');

        $response = $this->patchJson("/api/v1/admin/organizations/{$orgId}", [
            'enabled_modules' => [
                'sales' => true,
                'sales.pos' => false,
                'sales.backend' => true,
                'sales.reports' => true,
            ],
        ])->assertOk();

        $modules = $response->json('effective_modules');
        $this->assertFalse($modules['sales.pos']);
        $this->assertTrue($modules['sales.backend']);
        $this->assertTrue($modules['sales.reports']);
    }

    public function test_org_admin_cannot_change_enabled_modules(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/organizations/'.$orgAdmin->organization_id, [
            'enabled_modules' => ['sales.pos' => false],
        ])->assertOk();

        $org = \App\Models\Organization::findOrFail($orgAdmin->organization_id);
        $this->assertNotSame(['sales.pos' => false], $org->enabled_modules);
    }

    public function test_org_admin_cannot_change_organization_identity(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        $org = \App\Models\Organization::findOrFail($orgAdmin->organization_id);
        $originalCode = $org->company_code;
        $originalEmail = $org->org_email;

        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/organizations/'.$orgAdmin->organization_id, [
            'org_name' => 'Renamed By Org Admin',
            'company_code' => 'HACKED',
            'org_email' => 'hacked@example.com',
            'primary_tel' => '0799999999',
            'secondary_tel' => '0700111222',
            'org_address' => 'New address',
            'org_pin' => 'A12345678Z',
        ])->assertOk();

        $org->refresh();
        $this->assertSame('Renamed By Org Admin', $org->org_name);
        $this->assertSame($originalCode, $org->company_code);
        $this->assertSame($originalEmail, $org->org_email);
        $this->assertSame('0799999999', $org->primary_tel);
        $this->assertSame('0700111222', $org->secondary_tel);
        $this->assertSame('New address', $org->org_address);
        $this->assertSame('A12345678Z', $org->org_pin);
    }

    public function test_provision_requires_super_admin_and_feature_flag(): void
    {
        config(['erp.allow_org_provisioning' => false]);

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/organizations')->assertOk();

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

        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

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

        $cashier = User::create([
            'organization_id' => $orgAdmin->organization_id,
            'role_id' => 1,
            'username' => 'cashier_only',
            'password' => Hash::make('password123'),
            'full_name' => 'Cashier Only',
            'is_active' => true,
            'is_admin' => false,
            'is_super_admin' => false,
        ]);
        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/admin/organizations/provision', [
            'company_code' => 'BLOCKED3',
            'org_name' => 'Blocked Org 3',
            'org_email' => 'blocked3@test.com',
            'primary_tel' => '0700000002',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'small_shop',
            'admin_username' => 'mgr3',
            'admin_email' => 'mgr3@test.com',
            'admin_password' => 'password123',
            'admin_full_name' => 'Manager Three',
        ])->assertStatus(403);
    }

    public function test_scoped_username_uniqueness(): void
    {
        $org1 = \App\Models\Organization::create([
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

        $org2 = \App\Models\Organization::create([
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
        $org = \App\Models\Organization::create([
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

        $org = \App\Models\Organization::create([
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

    public function test_login_with_email(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'admin@demo.co.ke',
            'password' => 'password',
            'client_id' => 'PC_SETUP',
        ])->assertOk()->assertJsonStructure(['token', 'user', 'organization', 'memberships']);
    }

    public function test_super_admin_login_with_email_only(): void
    {
        $email = config('erp.platform_super_admin_email', 'alpacke.tech@gmail.com');

        $this->postJson('/api/v1/auth/login', [
            'company_code' => '',
            'username' => $email,
            'password' => 'password',
            'client_id' => 'PC_PLATFORM',
        ])->assertOk()
            ->assertJsonPath('user.is_super_admin', true)
            ->assertJsonPath('organization.company_code', config('erp.platform_company_code', 'PLATFORM'));
    }

    public function test_super_admin_login(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'PLATFORM',
            'username' => 'superadmin',
            'password' => 'password',
            'client_id' => 'PC_PLATFORM',
        ])->assertOk()
            ->assertJsonPath('user.is_super_admin', true)
            ->assertJsonPath('user.is_admin', false);
    }
}
