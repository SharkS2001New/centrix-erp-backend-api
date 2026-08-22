<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use InvalidArgumentException;

class AiProviderFactory
{
    /**
     * @param  array{provider?: string, api_key: string, model: string, base_url?: string}  $runtime
     */
    public function make(array $runtime): AiProviderInterface
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? config('ai.provider', 'openai'))));
        $apiKey = trim((string) ($runtime['api_key'] ?? ''));
        $model = trim((string) ($runtime['model'] ?? ''));
        $timeout = max(10, (int) config('ai.request_timeout', 60));

        if ($apiKey === '') {
            throw new InvalidArgumentException('AI provider API key is missing.');
        }

        return match ($provider) {
            'gemini' => new GeminiProvider(
                apiKey: $apiKey,
                model: $model !== '' ? $model : (string) config('ai.gemini.model', 'gemini-3.7-flash'),
                baseUrl: (string) ($runtime['base_url'] ?: config('ai.gemini.base_url')),
                timeoutSeconds: $timeout,
            ),
            'openai' => new OpenAiProvider(
                apiKey: $apiKey,
                model: $model !== '' ? $model : (string) config('ai.defaults.model', 'gpt-4o-mini'),
                baseUrl: (string) ($runtime['base_url'] ?: config('ai.defaults.base_url')),
                timeoutSeconds: $timeout,
            ),
            // Reserved for later: groq, openrouter, ollama
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
    }
}
