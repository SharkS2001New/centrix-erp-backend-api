<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\PlatformMailSettingsResolver;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SuperAdminEmailTwoFactorBypassTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_superadmin_can_login_when_email_2fa_cannot_send(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        $superAdmin->forceFill([
            'password' => Hash::make('Password123!'),
            'must_change_password' => false,
            'two_factor_enabled' => true,
            'two_factor_method' => 'email',
            'two_factor_confirmed_at' => now(),
            'email' => $superAdmin->email ?: 'superadmin@centrix.test',
            'email_verified_at' => now(),
        ])->save();

        $platform = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();
        $settings = $platform->module_settings ?? [];
        $mail = is_array($settings[PlatformMailSettingsResolver::SETTINGS_KEY] ?? null)
            ? $settings[PlatformMailSettingsResolver::SETTINGS_KEY]
            : [];
        $mail['enabled'] = false;
        $mail['auth_mail_use_dedicated'] = false;
        $settings[PlatformMailSettingsResolver::SETTINGS_KEY] = $mail;
        $platform->update(['module_settings' => $settings]);

        $this->assertFalse(PlatformMailSettingsResolver::canDeliverAuthMail());

        $this->postJson('/api/v1/auth/login', [
            'company_code' => 'PLATFORM',
            'username' => 'superadmin',
            'password' => 'Password123!',
            'client_id' => 'PC_PLATFORM',
        ])
            ->assertOk()
            ->assertJsonPath('user.is_super_admin', true)
            ->assertJsonMissingPath('mfa_required')
            ->assertJsonPath('warnings.0.code', 'platform_email_disabled');
    }
}
