<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Kra\KraAgentBridge;
use App\Services\Kra\KraDeviceService;
use App\Support\KraAgentToken;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraAgentBridgeTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_agent_package_issues_token_and_config(): void
    {
        $org = $this->admin->organization;
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'SN-TEST',
            'kra_pin_number' => 'P123456789X',
        ]);
        $org->update(['module_settings' => $settings]);

        $res = $this->postJson('/api/v1/kra/agent-package')->assertOk();
        $res->assertJsonPath('config.comstoreBaseUrl', 'http://127.0.0.1:4000');
        $this->assertNotEmpty($res->json('config.centrixToken'));
        $this->assertDatabaseHas('kra_agents', [
            'organization_id' => $org->id,
        ]);
    }

    public function test_device_health_uses_agent_bridge_when_enabled(): void
    {
        $orgId = (int) $this->admin->organization_id;
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization($orgId, [
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);
        $bridge->touchAgent($agent, '1.0.0-test');

        $finance = [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'SN',
            'kra_pin_number' => 'PIN',
            'kra_device_test_mode' => true,
        ];

        $service = KraDeviceService::fromSettings($finance, $orgId);
        $this->assertTrue($service->usesAgentBridge());

        $result = $service->checkHealth();
        $this->assertTrue($result['success'] ?? false);
        $this->assertTrue($result['via_agent'] ?? false);
        $this->assertSame('Connected', $result['device_connection'] ?? null);
    }

    public function test_agent_heartbeat_touches_last_seen(): void
    {
        $orgId = (int) $this->admin->organization_id;
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization($orgId, [
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);

        $tokenName = KraAgentToken::nameForOrganization($orgId);
        $this->admin->tokens()->where('name', $tokenName)->delete();
        $plain = $this->admin->createToken($tokenName, ['*'], null)->plainTextToken;
        DB::table('personal_access_tokens')
            ->where('name', $tokenName)
            ->update(['organization_id' => $orgId, 'expires_at' => null]);

        // Use the agent bearer token only (not Sanctum::actingAs session).
        $this->app['auth']->forgetGuards();

        $this->withToken($plain)
            ->postJson('/api/v1/kra/agent/heartbeat', [
                'agent_version' => '1.0.0',
                'comstore_base_url' => 'http://127.0.0.1:4000',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $agent->refresh();
        $this->assertNotNull($agent->agent_last_seen_at);
        $this->assertSame('1.0.0', $agent->agent_version);
    }
}
