<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\PasswordExpiryService;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PasswordExpiryTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enablePasswordExpiry(Organization $org, int $days = 90, int $maxSkips = 2): void
    {
        $settings = $org->module_settings ?? [];
        $settings['security'] = array_merge($settings['security'] ?? [], [
            'password_expiry_enabled' => true,
            'password_expiry_days' => $days,
            'password_expiry_max_skips' => $maxSkips,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_expired_password_allows_two_skips_then_forces_change(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->enablePasswordExpiry($org);

        $user = User::where('username', 'admin')->where('organization_id', $org->id)->firstOrFail();
        $user->forceFill([
            'must_change_password' => false,
            'password_changed_at' => now()->subDays(120),
            'password_expiry_skip_count' => 0,
        ])->save();

        Sanctum::actingAs($user);

        $status = app(PasswordExpiryService::class)->statusForUser($user->fresh());
        $this->assertTrue($status['expired']);
        $this->assertFalse($status['forced']);
        $this->assertSame(2, $status['skips_remaining']);

        $this->postJson('/api/v1/auth/skip-password-expiry')->assertOk();
        $this->postJson('/api/v1/auth/skip-password-expiry')->assertOk();

        $user->refresh();
        $this->assertSame(2, (int) $user->password_expiry_skip_count);

        $forced = app(PasswordExpiryService::class)->statusForUser($user);
        $this->assertTrue($forced['forced']);

        $this->getJson('/api/v1/products?per_page=1')
            ->assertStatus(403)
            ->assertJsonPath('code', 'password_expired_forced');

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'password',
            'password' => 'newsecurepass1',
            'password_confirmation' => 'newsecurepass1',
        ])->assertOk()
            ->assertJsonPath('password_expiry.forced', false);

        $user->refresh();
        $this->assertSame(0, (int) $user->password_expiry_skip_count);
        $this->assertFalse((bool) $user->must_change_password);

        $this->getJson('/api/v1/products?per_page=1')->assertOk();

        $user->forceFill(['password' => Hash::make('password')])->save();
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong-password',
            'password' => 'anotherpass1',
            'password_confirmation' => 'anotherpass1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }

    public function test_capabilities_include_password_expiry_status(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $this->enablePasswordExpiry($org);

        $user = User::where('username', 'admin')->where('organization_id', $org->id)->firstOrFail();
        $user->forceFill([
            'password_changed_at' => now()->subDays(120),
            'password_expiry_skip_count' => 0,
            'must_change_password' => false,
        ])->save();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/erp/capabilities')
            ->assertOk()
            ->assertJsonPath('password_expiry.expired', true)
            ->assertJsonPath('password_expiry.forced', false);
    }
}
