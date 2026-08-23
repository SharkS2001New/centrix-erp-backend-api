<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Ai\Providers\OllamaProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaProviderTest extends TestCase
{
    public function test_normalize_base_url_appends_v1(): void
    {
        $this->assertSame(
            'http://centrix-erp-ollama:11434/v1',
            OllamaProvider::normalizeBaseUrl('http://centrix-erp-ollama:11434'),
        );
        $this->assertSame(
            'http://127.0.0.1:11434/v1',
            OllamaProvider::normalizeBaseUrl('http://127.0.0.1:11434/v1'),
        );
    }

    public function test_factory_builds_ollama_without_cloud_api_key(): void
    {
        $provider = app(AiProviderFactory::class)->make([
            'provider' => 'ollama',
            'api_key' => '',
            'model' => 'llama3.2',
            'base_url' => 'http://ollama:11434',
        ]);

        $this->assertSame('ollama', $provider->name());
    }

    public function test_platform_ollama_credentials_resolve_from_config(): void
    {
        config([
            'ai.ollama.base_url' => 'http://centrix-erp-ollama:11434',
            'ai.ollama.model' => 'llama3.2',
            'ai.ollama.api_key' => 'ollama',
        ]);

        $credentials = AiSettingsResolver::resolvePlatformOllamaCredentials();
        $this->assertNotNull($credentials);
        $this->assertSame('llama3.2', $credentials['model']);
        $this->assertStringContainsString('/v1', $credentials['base_url']);
    }

    public function test_ollama_chat_uses_openai_compatible_endpoint(): void
    {
        Http::fake([
            'http://ollama.test:11434/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'Hello from Ollama'],
                ]],
                'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4, 'total_tokens' => 7],
            ]),
        ]);

        $provider = new OllamaProvider('ollama', 'llama3.2', 'http://ollama.test:11434', 30);
        $turn = $provider->chat([
            'system' => 'Test',
            'messages' => [['role' => 'user', 'content' => 'Hi']],
            'max_output_tokens' => 64,
        ]);

        $this->assertSame('Hello from Ollama', $turn['text']);
        $this->assertSame(7, $turn['usage']['total_tokens']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/chat/completions'));
    }

    public function test_free_provider_accepts_ollama(): void
    {
        config(['ai.free_provider' => 'ollama']);
        $this->assertSame('ollama', AiSettingsResolver::platformFreeAiProvider());
    }

    public function test_list_models_from_ollama_tags(): void
    {
        Http::fake([
            'http://ollama.test:11434/api/tags' => Http::response([
                'models' => [
                    ['name' => 'llama3.2:latest', 'size' => 100],
                    ['name' => 'mistral:latest', 'size' => 200],
                ],
            ]),
        ]);

        $models = OllamaProvider::listModels('http://ollama.test:11434');
        $this->assertCount(2, $models);
        $this->assertSame('llama3.2:latest', $models[0]['name']);
        $this->assertSame('mistral:latest', $models[1]['name']);
    }
}
