<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformAdminUserManagementTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_reset_tenant_user_password_via_nested_admin_route(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantUser = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantUser->organization_id;

        $this->putJson("/api/v1/admin/organizations/{$orgId}/users/{$tenantUser->id}", [
            'password' => 'Password123',
            'must_change_password' => true,
        ])->assertOk();

        $tenantUser->refresh();
        $this->assertTrue(Hash::check('Password123', $tenantUser->password));
        $this->assertTrue($tenantUser->must_change_password);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => Organization::findOrFail($orgId)->company_code,
            'username' => $tenantUser->username,
            'password' => 'Password123',
            'client_id' => 'WEB_TEST',
        ])->assertOk();
    }

    public function test_super_admin_can_create_tenant_user_via_nested_admin_route(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantUser = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantUser->organization_id;

        $response = $this->postJson("/api/v1/admin/organizations/{$orgId}/users", [
            'full_name' => 'Platform Created User',
            'username' => 'platformcreated',
            'email' => null,
            'password' => 'Password123',
            'access_scope' => 'org',
            'branch_id' => $tenantUser->branch_id,
            'role_id' => $tenantUser->role_id,
            'must_change_password' => true,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('username', 'platformcreated');

        $created = User::query()
            ->where('organization_id', $orgId)
            ->where('username', 'platformcreated')
            ->first();

        $this->assertNotNull($created);
        $this->assertSame('Platform Created User', $created->full_name);
        $this->assertTrue($created->must_change_password);
    }

    public function test_super_admin_can_clear_tenant_user_password_lock(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantUser = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantUser->organization_id;

        $tenantUser->forceFill([
            'must_change_password' => true,
            'password_changed_at' => null,
            'password_expiry_skip_count' => 0,
        ])->save();

        $this->postJson("/api/v1/admin/organizations/{$orgId}/users/{$tenantUser->id}/clear-password-lock")
            ->assertOk()
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonPath('user.password_locked', false)
            ->assertJsonPath('password_expiry.forced', false);

        $tenantUser->refresh();
        $this->assertFalse((bool) $tenantUser->must_change_password);
        $this->assertNotNull($tenantUser->password_changed_at);
    }

    public function test_super_admin_can_promote_and_demote_org_admin_when_more_than_one(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantAdmin->organization_id;
        $cashierRole = \App\Models\Role::where('role_name', 'Cashier')->firstOrFail();
        $adminRole = \App\Models\Role::where('role_name', 'Administrator')->where('scope', 'org')->firstOrFail();

        $staff = User::create([
            'organization_id' => $orgId,
            'branch_id' => $tenantAdmin->branch_id,
            'role_id' => $cashierRole->id,
            'username' => 'promote_me_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'Promote Me',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/users/{$staff->id}", [
            'is_admin' => true,
        ])
            ->assertOk()
            ->assertJsonPath('is_admin', true)
            ->assertJsonPath('role_id', $adminRole->id)
            ->assertJsonPath('access_scope', 'org');

        $this->assertTrue((bool) $staff->fresh()->is_admin);

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/users/{$staff->id}", [
            'is_admin' => false,
        ])
            ->assertOk()
            ->assertJsonPath('is_admin', false);

        $this->assertFalse((bool) $staff->fresh()->is_admin);
    }

    public function test_super_admin_cannot_demote_or_delete_sole_org_admin(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        $orgId = (int) $tenantAdmin->organization_id;

        User::query()
            ->where('organization_id', $orgId)
            ->where('id', '!=', $tenantAdmin->id)
            ->where('is_admin', true)
            ->update(['is_admin' => false]);

        $tenantAdmin->forceFill(['is_admin' => true])->save();

        $this->patchJson("/api/v1/admin/organizations/{$orgId}/users/{$tenantAdmin->id}", [
            'is_admin' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_admin']);

        $this->deleteJson("/api/v1/admin/organizations/{$orgId}/users/{$tenantAdmin->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['user']);

        $this->assertTrue((bool) $tenantAdmin->fresh()->is_admin);
    }

    public function test_tenant_admin_cannot_change_org_admin_flag_directly(): void
    {
        $tenantAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($tenantAdmin);

        \App\Models\PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $tenantAdmin->organization_id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        $cashierRole = \App\Models\Role::where('role_name', 'Cashier')->firstOrFail();
        $staff = User::create([
            'organization_id' => $tenantAdmin->organization_id,
            'branch_id' => $tenantAdmin->branch_id,
            'role_id' => $cashierRole->id,
            'username' => 'no_promote_'.uniqid(),
            'password' => Hash::make('password'),
            'full_name' => 'No Promote',
            'access_scope' => 'branch',
            'login_channels' => ['backoffice'],
            'is_admin' => false,
            'is_active' => true,
        ]);

        $this->putJson("/api/v1/users/{$staff->id}", [
            'is_admin' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['is_admin']);
    }
}
