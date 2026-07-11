<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\EmailVerificationService;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AuthEmailVerificationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_changing_email_clears_verification(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
        ])->save();

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/auth/me', [
            'email' => 'new-admin@example.com',
        ])
            ->assertOk()
            ->assertJsonPath('email', 'new-admin@example.com')
            ->assertJsonPath('email_verified_at', null);

        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_email_2fa_requires_verified_email(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'email' => 'admin@example.com',
            'email_verified_at' => null,
            'two_factor_enabled' => false,
            'two_factor_method' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/2fa/email/begin')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_user_can_verify_email_with_code(): void
    {
        Mail::fake();
        $this->enablePlatformMail();

        $user = User::where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'email' => 'verify-me@example.com',
            'email_verified_at' => null,
        ])->save();

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/email/verify/begin')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('email_verified', false);

        $cached = Cache::get('auth:email-verify:'.$user->id);
        $this->assertIsArray($cached);
        $this->assertNotEmpty($cached['code_hash'] ?? null);

        // Replace with a known code hash for deterministic confirm.
        Cache::put('auth:email-verify:'.$user->id, [
            'email' => 'verify-me@example.com',
            'code_hash' => Hash::make('123456'),
        ], now()->addMinutes(10));

        $this->postJson('/api/v1/auth/email/verify/confirm', [
            'code' => '123456',
        ])
            ->assertOk()
            ->assertJsonPath('email', 'verify-me@example.com');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $status = $this->getJson('/api/v1/auth/2fa')->assertOk()->json();
        $this->assertTrue($status['has_email']);
        $this->assertTrue($status['email_verified']);
    }

    public function test_email_verification_service_status(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $user->forceFill([
            'email' => 'status@example.com',
            'email_verified_at' => now(),
        ])->save();

        $status = app(EmailVerificationService::class)->statusForUser($user->fresh());

        $this->assertTrue($status['has_email']);
        $this->assertTrue($status['email_verified']);
    }

    protected function enablePlatformMail(): void
    {
        $org = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();

        if (! $org) {
            $this->markTestSkipped('PLATFORM organization not found.');
        }

        $settings = $org->module_settings ?? [];
        $settings[PlatformMailSettingsResolver::SETTINGS_KEY] = array_merge(
            PlatformMailSettingsResolver::defaults(),
            [
                'enabled' => true,
                'from_address' => 'platform@example.com',
                'from_name' => 'Centrix Test',
                'smtp_host' => 'smtp.example.com',
                'smtp_port' => 587,
                'smtp_username' => 'platform@example.com',
                'smtp_encryption' => 'tls',
                'smtp_password' => 'secret',
            ]
        );
        $org->module_settings = $settings;
        $org->save();
    }
}
