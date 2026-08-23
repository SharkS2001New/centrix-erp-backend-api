<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Http;
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

    public function test_health_returns_safe_payload_for_ollama(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        config([
            'ai.enabled' => true,
            'ai.provider' => 'ollama',
            'ai.ollama.base_url' => 'http://ollama.test:11434',
            'ai.ollama.model' => 'llama3.2',
            'ai.max_concurrent_requests' => 2,
        ]);

        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [['name' => 'llama3.2:latest']],
            ]),
        ]);

        $response = $this->getJson('/api/v1/ai/health');

        $response->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('provider', 'ollama')
            ->assertJsonPath('available', true)
            ->assertJsonPath('model', 'llama3.2')
            ->assertJsonPath('status', 'ONLINE')
            ->assertJsonPath('max_concurrent', 2);

        $json = $response->json();
        $this->assertArrayNotHasKey('base_url', $json);
        $this->assertArrayNotHasKey('api_key', $json);
        $encoded = json_encode($json);
        $this->assertStringNotContainsString('11434', (string) $encoded);
        $this->assertStringNotContainsString('/opt/', (string) $encoded);
    }
}
