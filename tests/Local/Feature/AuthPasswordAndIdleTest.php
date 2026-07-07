<?php


/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\SecuritySettingsResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthPasswordAndIdleTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_idle_session_is_not_revoked_when_server_revoke_disabled(): void
    {
        config([
            'erp.session_idle_minutes' => 15,
            'security.revoke_idle_tokens' => false,
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('idle-test');
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['last_used_at' => now()->subMinutes(20)]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_idle_session_is_revoked_when_server_revoke_enabled(): void
    {
        config([
            'erp.session_idle_minutes' => 15,
            'security.revoke_idle_tokens' => true,
        ]);

        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('idle-revoke-test');
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['last_used_at' => now()->subMinutes(20)]);

        $this->withToken($token->plainTextToken)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'session_idle_timeout');

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_security_settings_keep_screen_lock_below_session_idle(): void
    {
        $normalized = SecuritySettingsResolver::normalize([
            'screen_lock_minutes' => 60,
            'session_idle_minutes' => 60,
        ]);

        $this->assertSame(59, $normalized['screen_lock_minutes']);
        $this->assertSame(60, $normalized['session_idle_minutes']);
    }

    public function test_backoffice_token_expires_before_mobile_default(): void
    {
        config([
            'security.token_expiration_minutes_by_channel' => [
                'backoffice' => 60,
                'pos' => 1440,
                'mobile' => 1440,
            ],
        ]);

        $this->assertSame(60, SecuritySettingsResolver::tokenExpirationMinutesForChannel('backoffice'));
        $this->assertSame(1440, SecuritySettingsResolver::tokenExpirationMinutesForChannel('mobile'));
    }

    public function test_forgot_and_reset_password(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $user = User::where('username', 'admin')->where('organization_id', $org->id)->firstOrFail();

        $forgot = $this->postJson('/api/v1/auth/forgot-password', [
            'company_code' => 'DEMO',
            'username' => 'admin',
        ]);

        $forgot->assertOk()
            ->assertJsonStructure(['message', 'reset_url']);

        $resetUrl = $forgot->json('reset_url');
        $this->assertNotEmpty($resetUrl);

        parse_str(parse_url($resetUrl, PHP_URL_QUERY), $query);
        $token = $query['token'] ?? null;
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'company_code' => 'DEMO',
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'admin',
            'password' => 'newpassword123',
            'client_id' => 'PC_RESET',
        ])->assertOk();

        $user->forceFill(['password' => Hash::make('password')])->save();
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong',
            'password' => 'anotherpass',
            'password_confirmation' => 'anotherpass',
        ])->assertStatus(422);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'anotherpass',
            'password_confirmation' => 'anotherpass',
        ])->assertOk();
    }

    public function test_verify_password_for_screen_unlock(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('unlock-test');

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/verify-password', [
                'password' => 'wrong',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password']);

        $this->withToken($token->plainTextToken)
            ->postJson('/api/v1/auth/verify-password', [
                'password' => 'password',
            ])
            ->assertOk()
            ->assertJsonPath('verified', true);

        $this->assertTrue(
            $user->tokens()->where('last_used_at', '>=', now()->subMinute())->exists(),
        );
    }

    public function test_username_unique_per_organization_on_create(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $branchId = $admin->branch_id ?? 1;

        $this->postJson('/api/v1/users', [
            'full_name' => 'Duplicate Admin',
            'username' => 'admin',
            'password' => 'password123',
            'role_id' => 1,
            'branch_id' => $branchId,
            'access_scope' => 'branch',
            'is_active' => true,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['username']);
    }
}
