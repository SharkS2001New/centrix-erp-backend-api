<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class CentrixAsMobileFullErpAccessTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_as_mobile_session_is_not_blocked_by_field_mobile_path_allowlist(): void
    {
        $org = Organization::where('company_code', 'DEMO')->firstOrFail();
        $admin = User::where('username', 'admin')->firstOrFail();

        $user = User::query()->create([
            'organization_id' => $org->id,
            'branch_id' => $admin->branch_id,
            'role_id' => $admin->role_id,
            'username' => 'as_full_erp_'.uniqid(),
            'email' => null,
            'password' => Hash::make('password'),
            'full_name' => 'AS Full ERP User',
            'is_admin' => true,
            'access_scope' => 'org',
            'login_channels' => ['backoffice', 'centrix_as_mobile'],
            'is_active' => true,
        ]);

        $issued = $user->createToken('AS_FULL_ERP', ['*']);
        $issued->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'centrix_as_mobile',
        ])->save();

        $token = $issued->plainTextToken;
        $today = now()->toDateString();

        // Paths Field Mobile rejects — CentrixAsMobile must pass the login-channel gate
        // (module/permission may still 403/422 afterward; that is ERP gating, not Field Mobile).
        $eod = $this->withToken($token)
            ->getJson("/api/v1/reports/eod-report?from_date={$today}&to_date={$today}");
        $this->assertNotSame(
            'login_channel_forbidden',
            $eod->json('code'),
            'CentrixAsMobile must not be limited by the Field Mobile path allowlist',
        );

        $this->withToken($token)
            ->getJson('/api/v1/products?per_page=1')
            ->assertOk();

        $this->withToken($token)
            ->getJson('/api/v1/branches')
            ->assertOk();

        // Field Mobile still blocked on the same report.
        $field = $user->createToken('FIELD_ONLY', ['*']);
        $field->accessToken->forceFill([
            'organization_id' => $user->organization_id,
            'login_channel' => 'mobile',
        ])->save();

        $this->withToken($field->plainTextToken)
            ->getJson("/api/v1/reports/eod-report?from_date={$today}&to_date={$today}")
            ->assertStatus(403)
            ->assertJsonPath('code', 'login_channel_forbidden');
    }
}
