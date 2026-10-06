<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Platform\OrganizationLicenseService;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationLicenseGateTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_missing_subscription_is_treated_as_expired(): void
    {
        $licenses = app(OrganizationLicenseService::class);

        $this->assertTrue($licenses->isExpired([
            'status' => 'missing',
            'expires_at' => null,
            'days_remaining' => null,
        ]));
    }

    public function test_login_blocked_when_organization_has_no_subscription(): void
    {
        $org = Organization::query()
            ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->delete();

        $template = User::query()
            ->where('organization_id', $org->id)
            ->where('is_super_admin', false)
            ->firstOrFail();

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $template->branch_id,
            'role_id' => $template->role_id,
            'username' => 'nolicence_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'No Licence User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos', 'mobile'],
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => $org->company_code,
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_NO_LICENCE',
            'login_channel' => 'backoffice',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'organization_subscription_required');
    }

    public function test_calendar_day_after_period_end_is_expired_and_blocks_login(): void
    {
        $org = Organization::query()
            ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        $yesterday = now()->subDay()->toDateString();
        PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => $yesterday,
                'is_trial' => false,
                'trial_ends_at' => null,
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        $licenses = app(OrganizationLicenseService::class);
        $license = $licenses->resolveForOrganization($org->fresh());
        $this->assertSame('expired', $license['status'] ?? null);
        $this->assertLessThan(0, (int) ($license['days_remaining'] ?? 0));
        $this->assertTrue($licenses->isExpired($license));

        $template = User::query()
            ->where('organization_id', $org->id)
            ->where('is_super_admin', false)
            ->firstOrFail();

        $user = User::create([
            'organization_id' => $org->id,
            'branch_id' => $template->branch_id,
            'role_id' => $template->role_id,
            'username' => 'expired_lic_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'Expired Licence User',
            'is_admin' => false,
            'access_scope' => 'branch',
            'login_channels' => ['backoffice', 'pos', 'mobile'],
            'is_active' => true,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'company_code' => $org->company_code,
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_EXPIRED_LICENCE',
            'login_channel' => 'backoffice',
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'organization_license_expired');
    }

    public function test_licence_still_valid_on_period_end_calendar_day(): void
    {
        $org = Organization::query()
            ->where('company_code', '!=', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail();

        PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $org->id],
            [
                'status' => 'active',
                'current_period_start' => now()->subMonth()->toDateString(),
                'current_period_end' => now()->toDateString(),
                'is_trial' => false,
                'trial_ends_at' => null,
                'renewal_price' => 0,
                'amount' => 0,
                'currency' => 'KES',
            ],
        );

        $licenses = app(OrganizationLicenseService::class);
        $license = $licenses->resolveForOrganization($org->fresh());
        $this->assertSame(0, (int) ($license['days_remaining'] ?? -1));
        $this->assertFalse($licenses->isExpired($license));
    }
}
