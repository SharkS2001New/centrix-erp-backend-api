<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthPasswordAndIdleTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_idle_session_is_revoked(): void
    {
        config(['erp.session_idle_minutes' => 15]);

        $user = User::where('username', 'admin')->firstOrFail();
        $token = $user->createToken('idle-test');
        DB::table('personal_access_tokens')
            ->where('id', $token->accessToken->id)
            ->update(['last_used_at' => now()->subMinutes(20)]);

        $response = $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me');
        $this->assertSame(401, $response->status());
        $response->assertJsonFragment(['code' => 'session_idle_timeout']);
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
