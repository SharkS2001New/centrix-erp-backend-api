<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Mobile\UserDeviceTokenService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserDeviceTokenOrgScopeTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_registering_token_replaces_other_org_sessions_for_same_device(): void
    {
        $orgA = Organization::factory()->create(['company_code' => 'ORGA']);
        $orgB = Organization::factory()->create(['company_code' => 'ORGB']);
        $userA = User::factory()->create(['organization_id' => $orgA->id]);
        $userB = User::factory()->create(['organization_id' => $orgB->id]);

        $service = app(UserDeviceTokenService::class);
        $service->register($userA, 'shared-device-token', UserDeviceToken::CHANNEL_MANAGER, 'android');
        $service->register($userB, 'shared-device-token', UserDeviceToken::CHANNEL_MANAGER, 'android');

        $this->assertDatabaseMissing('user_device_tokens', [
            'user_id' => $userA->id,
            'token' => 'shared-device-token',
        ]);
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $userB->id,
            'organization_id' => $orgB->id,
            'token' => 'shared-device-token',
            'app_channel' => UserDeviceToken::CHANNEL_MANAGER,
        ]);
    }

    public function test_manager_device_token_registration_stores_organization_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/manager/device-tokens', [
            'token' => 'mgr-org-token-123',
            'platform' => 'android',
        ])->assertCreated();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $admin->id,
            'organization_id' => $admin->organization_id,
            'token' => 'mgr-org-token-123',
            'app_channel' => UserDeviceToken::CHANNEL_MANAGER,
        ]);
    }
}
