<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use App\Services\Kra\KraAgentBridge;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraDeviceHealthTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);

        $org = Organization::findOrFail($this->user->organization_id);
        $settings = $org->module_settings ?? [];
        $settings['finance'] = array_merge($settings['finance'] ?? [], [
            'enable_kra_device' => true,
            'enable_kra_agent' => true,
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);

        // Agent must look warm so Test connection can proxy through the bridge.
        $bridge = app(KraAgentBridge::class);
        $agent = $bridge->resolveOrCreateForOrganization((int) $org->id, $settings['finance']);
        $bridge->touchAgent($agent, '1.3.2-test', true, '');
    }

    public function test_device_health_calls_api_health_on_configured_device(): void
    {
        $this->postJson('/api/v1/kra/device-health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'http://192.168.1.50:8010/api/health')
            ->assertJsonPath('device_connection', 'Connected')
            ->assertJsonPath('via_agent', true);
    }

    public function test_device_health_fails_when_fiscal_hardware_disconnected(): void
    {
        config([
            'testing.kra_agent_health_response' => [
                'status' => 'OK',
                'apiService' => 'Running',
                'deviceConnection' => 'Disconnected',
            ],
        ]);

        $this->postJson('/api/v1/kra/device-health')
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('comstore_ok', false)
            ->assertJsonPath('device_connection', 'Disconnected');
    }

    public function test_device_health_pings_fiscal_hardware_ip_via_agent(): void
    {
        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_hardware_ip' => '192.168.1.39',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('device_hardware_ip', '192.168.1.39')
            ->assertJsonPath('device_ok', true)
            ->assertJsonPath('device_ping_ok', true);
    }

    public function test_device_health_reports_unreachable_hardware_ip(): void
    {
        config([
            'testing.kra_agent_device_probe_response' => [
                'success' => false,
                'reachable' => false,
                'hardware_ip' => '192.168.1.39',
                'device_connection' => null,
                'ping_ok' => false,
                'comstore_healthy' => true,
                'message' => 'Fiscal device network error — cannot reach 192.168.1.39. ICMP TimedOut',
            ],
        ]);

        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_hardware_ip' => '192.168.1.39',
        ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('comstore_ok', true)
            ->assertJsonPath('device_ok', false)
            ->assertJsonPath('device_ping_ok', false);
    }

    public function test_device_health_does_not_require_shop_pin(): void
    {
        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_pin_number' => '',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_device_health_accepts_draft_device_url(): void
    {
        Http::fake();

        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
        ])->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('url', 'https://kramoonstores.example.test/api/health');
    }

    public function test_device_health_defaults_empty_device_url_to_localhost(): void
    {
        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => '',
        ])->assertOk()->assertJsonPath('url', 'http://localhost:4000/api/health');
    }
}
