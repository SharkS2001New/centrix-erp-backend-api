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
        $res->assertJsonPath('config.longPollMs', 2000);
        $res->assertJsonPath('config.autoStartComstore', true);
        $this->assertNotEmpty($res->json('config.centrixToken'));
        $this->assertDatabaseHas('kra_agents', [
            'organization_id' => $org->id,
        ]);
    }

    public function test_agent_pending_long_poll_returns_when_command_queued(): void
    {
        $orgId = (int) $this->admin->organization_id;
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization($orgId, [
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);
        $bridge->touchAgent($agent, '1.1.0-test');

        $tokenName = KraAgentToken::nameForOrganization($orgId);
        $this->admin->tokens()->where('name', $tokenName)->delete();
        $plain = $this->admin->createToken($tokenName, ['*'], null)->plainTextToken;
        DB::table('personal_access_tokens')
            ->where('name', $tokenName)
            ->update(['organization_id' => $orgId, 'expires_at' => null]);

        $this->app['auth']->forgetGuards();

        // Queue a command while the long-poll is about to start.
        $commandId = (string) \Illuminate\Support\Str::uuid();
        \App\Models\KraAgentCommand::query()->create([
            'id' => $commandId,
            'kra_agent_id' => $agent->id,
            'method' => 'GET',
            'path' => '/api/health',
            'body_json' => null,
            'accept' => 'json',
            'status' => 'pending',
            'created_at' => now()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addMinutes(2)->format('Y-m-d H:i:s'),
        ]);

        $started = microtime(true);
        $res = $this->withToken($plain)
            ->getJson('/api/v1/kra/agent/commands/pending?limit=5&wait_ms=2000&agent_version=1.1.0')
            ->assertOk();
        $elapsedMs = (microtime(true) - $started) * 1000;

        $res->assertJsonPath('commands.0.id', $commandId);
        $res->assertJsonPath('commands.0.path', '/api/health');
        // Should wake promptly when work is already queued (not sit for the full wait_ms).
        $this->assertLessThan(500, $elapsedMs, 'Pending long-poll should return immediately when a command is already queued');
    }

    public function test_agent_pending_long_poll_waits_when_empty_then_returns(): void
    {
        $orgId = (int) $this->admin->organization_id;
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization($orgId, [
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);
        $bridge->touchAgent($agent, '1.1.0-test');

        $tokenName = KraAgentToken::nameForOrganization($orgId);
        $this->admin->tokens()->where('name', $tokenName)->delete();
        $plain = $this->admin->createToken($tokenName, ['*'], null)->plainTextToken;
        DB::table('personal_access_tokens')
            ->where('name', $tokenName)
            ->update(['organization_id' => $orgId, 'expires_at' => null]);

        $this->app['auth']->forgetGuards();

        $started = microtime(true);
        $res = $this->withToken($plain)
            ->getJson('/api/v1/kra/agent/commands/pending?limit=5&wait_ms=250&agent_version=1.1.0')
            ->assertOk();
        $elapsedMs = (microtime(true) - $started) * 1000;

        $res->assertJsonPath('commands', []);
        $this->assertGreaterThanOrEqual(200, $elapsedMs, 'Empty long-poll should hold near wait_ms');
        $this->assertLessThan(1200, $elapsedMs, 'Empty long-poll should not overshoot wait_ms badly');
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

    public function test_agent_heartbeat_records_comstore_manual_start(): void
    {
        $org = $this->admin->organization;
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);
        $org->update(['module_settings' => $settings]);

        $orgId = (int) $org->id;
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

        $this->app['auth']->forgetGuards();

        $this->withToken($plain)
            ->postJson('/api/v1/kra/agent/heartbeat', [
                'agent_version' => '1.1.0',
                'comstore_base_url' => 'http://127.0.0.1:4000',
                'comstore_healthy' => false,
                'comstore_message' => 'COMSTORE_MANUAL_START_REQUIRED: Please start Comstore manually',
            ])
            ->assertOk()
            ->assertJsonPath('agent.comstore_reachable', false)
            ->assertJsonPath('agent.manual_start_required', true);

        Sanctum::actingAs($this->admin);
        $status = $this->getJson('/api/v1/kra/agent/status')->assertOk();
        $status->assertJsonPath('manual_start_required', true);
        $this->assertStringContainsString('start Comstore manually', (string) $status->json('message'));
    }

    public function test_device_health_surfaces_manual_comstore_start_when_agent_reports_it(): void
    {
        $orgId = (int) $this->admin->organization_id;
        $realBridge = app(KraAgentBridge::class);
        $agent = $realBridge->resolveOrCreateForOrganization($orgId, [
            'kra_device_ip' => 'http://127.0.0.1:4000',
        ]);
        $realBridge->touchAgent($agent, '1.1.0-test');

        $bridge = \Mockery::mock(KraAgentBridge::class)->makePartial();
        $bridge->shouldReceive('executeViaAgent')
            ->once()
            ->andThrow(new \RuntimeException(
                'COMSTORE_MANUAL_START_REQUIRED: Could not start Comstore automatically. Please start Comstore manually on this shop PC.',
            ));
        $bridge->shouldReceive('touchAgent')->andReturnUsing(
            function ($a, $version = null, $reachable = null, $message = null) use ($realBridge) {
                $realBridge->touchAgent($a, $version, $reachable, $message);
            },
        );

        $finance = [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://127.0.0.1:4000',
            'kra_serial_number' => 'SN',
            'kra_pin_number' => 'PIN',
            'kra_device_test_mode' => true,
        ];

        $service = KraDeviceService::fromSettings($finance, $orgId);
        $service->useAgentBridge($bridge, $agent);

        $result = $service->checkHealth();
        $this->assertFalse($result['success']);
        $this->assertTrue($result['manual_start_required'] ?? false);
        $this->assertStringContainsString('start Comstore manually', (string) $result['message']);
    }
}
