<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Mobile\FcmPushSettingsResolver;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformFcmPushSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_manage_platform_push_settings(): void
    {
        $platformOrg = Organization::where('company_code', config('erp.platform_company_code', 'PLATFORM'))->first();
        if (! $platformOrg) {
            $platformOrg = Organization::factory()->create([
                'company_code' => config('erp.platform_company_code', 'PLATFORM'),
                'org_name' => 'Platform Administration',
            ]);
        }

        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->getJson('/api/v1/admin/push/settings')
            ->assertOk()
            ->assertJsonPath('scope', 'platform')
            ->assertJsonStructure([
                'settings',
                'effective',
                'credentials_set',
                'diagnostics' => ['enabled', 'ready'],
                'apps',
            ]);

        $credentials = json_encode([
            'type' => 'service_account',
            'project_id' => 'centrix-test',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
            'client_email' => 'fcm@centrix-test.iam.gserviceaccount.com',
        ], JSON_UNESCAPED_SLASHES);

        $this->patchJson('/api/v1/admin/push/settings', [
            'enabled' => true,
            'fcm_project_id' => 'centrix-test',
            'ignore_local_tokens' => true,
            'credentials_json' => $credentials,
        ])->assertOk()
            ->assertJsonPath('effective.enabled', true)
            ->assertJsonPath('effective.fcm_project_id', 'centrix-test')
            ->assertJsonPath('credentials_set', true)
            ->assertJsonPath('credentials_client_email', 'fcm@centrix-test.iam.gserviceaccount.com');

        $this->assertFileExists(FcmPushSettingsResolver::platformCredentialsPath());

        $this->patchJson('/api/v1/admin/push/settings', [
            'clear_credentials' => true,
            'enabled' => false,
        ])->assertOk()
            ->assertJsonPath('credentials_set', false)
            ->assertJsonPath('effective.enabled', false);

        $this->assertFileDoesNotExist(FcmPushSettingsResolver::platformCredentialsPath());
    }

    public function test_org_admin_cannot_manage_platform_push_settings(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($orgAdmin);

        $this->getJson('/api/v1/admin/push/settings')->assertForbidden();
        $this->patchJson('/api/v1/admin/push/settings', ['enabled' => true])->assertForbidden();
    }
}
