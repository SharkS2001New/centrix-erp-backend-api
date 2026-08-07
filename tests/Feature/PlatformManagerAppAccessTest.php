<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformManagerAppAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function platformManagerToken(string $clientId): string
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $superAdmin->forceFill([
            'password' => Hash::make('Password123!'),
            'must_change_password' => false,
            'login_channels' => ['backoffice', 'manager'],
            'is_super_admin' => true,
            'is_active' => true,
        ])->save();

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'PLATFORM',
            'username' => 'superadmin',
            'password' => 'Password123!',
            'client_id' => $clientId,
            'login_channel' => 'manager',
        ])->assertOk();

        $login->assertJsonPath('user.is_super_admin', true);

        return (string) $login->json('token');
    }

    public function test_platform_super_admin_can_login_via_manager_channel(): void
    {
        $token = $this->platformManagerToken('PLATFORM_MANAGER_TEST');

        $this->withToken($token)
            ->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('is_super_admin', true)
            ->assertJsonPath('manager_app.accessible', true);
    }

    public function test_platform_manager_token_can_access_admin_platform_apis(): void
    {
        $token = $this->platformManagerToken('PLATFORM_MANAGER_ADMIN_TEST');

        $this->withToken($token)
            ->getJson('/api/v1/admin/organizations')
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/platform-health')
            ->assertOk();
    }

    public function test_tenant_manager_token_still_denied_admin_paths(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $admin->forceFill([
            'password' => Hash::make('password'),
            'must_change_password' => false,
            'login_channels' => ['backoffice', 'manager'],
        ])->save();

        $org = Organization::findOrFail($admin->organization_id);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => $org->company_code,
            'username' => $admin->username,
            'password' => 'password',
            'client_id' => 'TENANT_MANAGER_ADMIN_DENY',
            'login_channel' => 'manager',
            'force_logout' => true,
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/admin/organizations')
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }
}
