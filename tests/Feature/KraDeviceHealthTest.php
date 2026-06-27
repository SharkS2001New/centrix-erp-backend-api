<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
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
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_device_health_calls_api_health_on_configured_device(): void
    {
        Http::fake([
            '192.168.1.50:8010/api/health' => Http::response([
                'status' => 'OK',
                'version' => '1.6.1',
                'apiService' => 'Running',
                'deviceConnection' => 'Connected',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('url', 'http://192.168.1.50:8010/api/health')
            ->assertJsonPath('device_connection', 'Connected')
            ->assertJsonPath('api_service', 'Running');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_ends_with($request->url(), '/api/health'));
    }

    public function test_device_health_fails_when_fiscal_hardware_disconnected(): void
    {
        Http::fake([
            '192.168.1.50:8010/api/health' => Http::response([
                'status' => 'OK',
                'apiService' => 'Running',
                'deviceConnection' => 'Disconnected',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-health')
            ->assertStatus(502)
            ->assertJsonPath('success', false)
            ->assertJsonPath('device_connection', 'Disconnected');
    }

    public function test_device_health_does_not_require_shop_pin(): void
    {
        Http::fake([
            '192.168.1.50:8010/api/health' => Http::response(['status' => 'OK', 'deviceConnection' => 'Connected'], 200),
        ]);

        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => 'http://192.168.1.50:8010',
            'kra_pin_number' => '',
        ])->assertOk();
    }

    public function test_device_health_accepts_draft_device_url(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/health' => Http::response(['status' => 'OK', 'deviceConnection' => 'Connected'], 200),
        ]);

        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
        ])->assertOk()->assertJsonPath('success', true);
    }

    public function test_device_health_requires_device_url(): void
    {
        $this->postJson('/api/v1/kra/device-health', [
            'kra_device_ip' => '',
        ])->assertStatus(422);
    }
}
