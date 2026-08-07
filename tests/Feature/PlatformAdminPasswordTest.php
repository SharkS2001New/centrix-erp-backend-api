<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformAdminPasswordTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_platform_admin_can_change_password_without_current(): void
    {
        $admin = User::where('username', 'superadmin')->firstOrFail();
        $this->assertTrue((bool) $admin->is_super_admin);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/auth/change-password', [
            'password' => 'PlatformNewPass1',
            'password_confirmation' => 'PlatformNewPass1',
        ])->assertOk();

        $admin->refresh();
        $this->assertTrue(Hash::check('PlatformNewPass1', $admin->password));
    }

    public function test_platform_admin_can_view_bootstrap_password_when_it_matches(): void
    {
        $admin = User::where('username', 'superadmin')->firstOrFail();
        $bootstrap = (string) config('erp.platform_super_admin_password');
        $this->assertNotSame('', $bootstrap);

        $admin->forceFill(['password' => Hash::make($bootstrap)])->save();
        Sanctum::actingAs($admin->fresh());

        $this->getJson('/api/v1/auth/platform-admin-current-password')
            ->assertOk()
            ->assertJsonPath('matches_bootstrap', true)
            ->assertJsonPath('password', $bootstrap);
    }

    public function test_tenant_admin_cannot_view_platform_bootstrap_password(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $this->assertFalse((bool) $user->is_super_admin);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/platform-admin-current-password')
            ->assertForbidden();
    }

    public function test_tenant_user_still_requires_current_password(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'password' => 'anotherpass1',
            'password_confirmation' => 'anotherpass1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
