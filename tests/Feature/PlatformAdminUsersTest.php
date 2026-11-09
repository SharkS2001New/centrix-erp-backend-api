<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Platform\PlatformAdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PlatformAdminUsersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoDataSeeder::class);
    }

    protected function actingAsPlatformOwner(): User
    {
        $owner = User::query()->where('is_super_admin', true)->where('is_active', true)->firstOrFail();
        $owner->forceFill([
            'platform_permissions' => null,
            'two_factor_enabled' => true,
            'two_factor_method' => TwoFactorService::METHOD_TOTP,
            'two_factor_secret' => 'test-secret',
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($owner, 'sanctum');

        return $owner->fresh();
    }

    public function test_owner_can_create_limited_platform_admin(): void
    {
        config(['erp.platform_require_two_factor' => true]);
        $this->actingAsPlatformOwner();

        $res = $this->postJson('/api/v1/admin/platform-admins', [
            'username' => 'ops.admin',
            'email' => 'ops.admin@example.test',
            'full_name' => 'Ops Admin',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'platform_permissions' => [
                PlatformAdminPermissions::OPERATIONS,
                PlatformAdminPermissions::ADMINS,
            ],
        ]);

        $res->assertCreated()
            ->assertJsonPath('data.full_access', false)
            ->assertJsonPath('data.two_factor_enabled', false);

        $created = User::query()->whereUsernameInsensitive('ops.admin')->first();
        $this->assertNotNull($created);
        $this->assertSame(strtoupper($created->username), strtoupper((string) data_get($res->json(), 'data.username')));
        $this->assertTrue((bool) $created->is_super_admin);
        $this->assertSame(
            [PlatformAdminPermissions::OPERATIONS, PlatformAdminPermissions::ADMINS],
            $created->platform_permissions,
        );
    }

    public function test_limited_admin_without_2fa_is_blocked_from_platform_apis(): void
    {
        config(['erp.platform_require_two_factor' => true]);
        $owner = $this->actingAsPlatformOwner();

        $limited = User::query()->create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $owner->branch_id,
            'role_id' => $owner->role_id,
            'username' => 'limited.admin',
            'email' => 'limited@example.test',
            'full_name' => 'Limited Admin',
            'password' => Hash::make('Password1!'),
            'is_super_admin' => true,
            'platform_permissions' => [PlatformAdminPermissions::TENANTS],
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => true,
            'two_factor_enabled' => false,
        ]);

        $this->actingAs($limited, 'sanctum');

        $this->getJson('/api/v1/admin/organizations')
            ->assertForbidden()
            ->assertJsonPath('code', 'platform_two_factor_required');
    }

    public function test_limited_admin_cannot_access_other_areas(): void
    {
        config(['erp.platform_require_two_factor' => false]);
        $owner = $this->actingAsPlatformOwner();

        $limited = User::query()->create([
            'organization_id' => $owner->organization_id,
            'branch_id' => $owner->branch_id,
            'role_id' => $owner->role_id,
            'username' => 'billing.only',
            'email' => 'billing@example.test',
            'full_name' => 'Billing Only',
            'password' => Hash::make('Password1!'),
            'is_super_admin' => true,
            'platform_permissions' => [PlatformAdminPermissions::BILLING],
            'access_scope' => 'org',
            'login_channels' => ['backoffice'],
            'is_active' => true,
        ]);

        $this->actingAs($limited, 'sanctum');

        $this->getJson('/api/v1/admin/platform-admins')
            ->assertForbidden()
            ->assertJsonPath('code', 'platform_permission_denied');
    }

    public function test_platform_admin_cannot_disable_two_factor(): void
    {
        $owner = $this->actingAsPlatformOwner();
        $owner->forceFill(['password' => Hash::make('Password1!')])->save();

        $this->postJson('/api/v1/auth/2fa/disable', [
            'password' => 'Password1!',
        ])->assertStatus(422);

        $this->assertTrue(app(TwoFactorService::class)->isEnabled($owner->fresh()));
    }
}
