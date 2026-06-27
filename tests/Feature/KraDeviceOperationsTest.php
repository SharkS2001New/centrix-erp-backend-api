<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class KraDeviceOperationsTest extends TestCase
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
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
            'kra_serial_number' => 'DEJA02220240050',
            'kra_pin_number' => 'P052177271G',
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_device_init_posts_serial_and_hardware_ip(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/init' => Http::response([
                'status' => 'OK',
                'version' => '1.6.1',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-init', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
            'kra_serial_number' => 'DEJA02220240050',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/init')) {
                return false;
            }

            $body = $request->data();

            return ($body['sn'] ?? '') === 'DEJA02220240050'
                && ($body['ip'] ?? '') === '192.168.1.39';
        });
    }

    public function test_device_init_requires_hardware_ip_for_hostname_api_url(): void
    {
        $this->postJson('/api/v1/kra/device-init', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '',
            'kra_serial_number' => 'DEJA02220240050',
        ])->assertStatus(422);
    }

    public function test_device_restart_posts_hardware_ip(): void
    {
        Http::fake([
            'kramoonstores.example.test/api/restart-device' => Http::response([
                'success' => true,
                'message' => 'Device restart initiated.',
                'device_ip' => '192.168.1.39',
            ], 200),
        ]);

        $this->postJson('/api/v1/kra/device-restart', [
            'kra_device_ip' => 'https://kramoonstores.example.test',
            'kra_device_hardware_ip' => '192.168.1.39',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/api/restart-device')) {
                return false;
            }

            return ($request->data()['ip_address'] ?? '') === '192.168.1.39';
        });
    }
}
