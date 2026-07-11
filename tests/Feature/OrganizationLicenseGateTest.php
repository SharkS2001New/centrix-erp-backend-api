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
}
