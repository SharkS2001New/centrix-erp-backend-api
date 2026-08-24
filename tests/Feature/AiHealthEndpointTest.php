<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class AiHealthEndpointTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_health_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/ai/health');

        $this->assertTrue(in_array($response->status(), [401, 403], true));
    }

    public function test_health_returns_safe_payload_for_gemini(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        config([
            'ai.enabled' => true,
            'ai.provider' => 'gemini',
            'ai.gemini.api_key' => 'test-gemini-key',
            'ai.gemini.model' => 'gemini-3.6-flash',
            'ai.max_concurrent_requests' => 2,
        ]);

        $response = $this->getJson('/api/v1/ai/health');

        $response->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('provider', 'gemini')
            ->assertJsonPath('available', true)
            ->assertJsonPath('model', 'gemini-3.6-flash')
            ->assertJsonPath('status', 'ONLINE')
            ->assertJsonPath('max_concurrent', 2);

        $json = $response->json();
        $this->assertArrayNotHasKey('base_url', $json);
        $this->assertArrayNotHasKey('api_key', $json);
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('test-gemini-key', (string) $encoded);
    }
}
