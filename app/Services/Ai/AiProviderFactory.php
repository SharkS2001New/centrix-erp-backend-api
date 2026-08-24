<?php

namespace App\Services\Ai;

use App\Contracts\Ai\AiProviderInterface;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use InvalidArgumentException;

class AiProviderFactory
{
    /**
     * @param  array{provider?: string, api_key?: string, model: string, base_url?: string}  $runtime
     */
    public function make(array $runtime): AiProviderInterface
    {
        $provider = strtolower(trim((string) ($runtime['provider'] ?? config('ai.provider', 'openai'))));
        $apiKey = trim((string) ($runtime['api_key'] ?? ''));
        $model = trim((string) ($runtime['model'] ?? ''));
        $timeout = max(10, (int) config('ai.request_timeout', 60));

        return match ($provider) {
            'gemini' => $this->makeGemini($apiKey, $model, $runtime, $timeout),
            'openai' => $this->makeOpenAi($apiKey, $model, $runtime, $timeout),
            default => throw new InvalidArgumentException("Unsupported AI provider [{$provider}]."),
        };
    }

    /**
     * @param  array{base_url?: string}  $runtime
     */
    protected function makeGemini(string $apiKey, string $model, array $runtime, int $timeout): GeminiProvider
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('AI provider API key is missing.');
        }

        return new GeminiProvider(
            apiKey: $apiKey,
            model: $model !== '' ? $model : (string) config('ai.gemini.model', 'gemini-3.6-flash'),
            baseUrl: (string) ($runtime['base_url'] ?: config('ai.gemini.base_url')),
            timeoutSeconds: $timeout,
        );
    }

    /**
     * @param  array{base_url?: string}  $runtime
     */
    protected function makeOpenAi(string $apiKey, string $model, array $runtime, int $timeout): OpenAiProvider
    {
        if ($apiKey === '') {
            throw new InvalidArgumentException('AI provider API key is missing.');
        }

        return new OpenAiProvider(
            apiKey: $apiKey,
            model: $model !== '' ? $model : (string) config('ai.defaults.model', 'gpt-4o-mini'),
            baseUrl: (string) ($runtime['base_url'] ?: config('ai.defaults.base_url')),
            timeoutSeconds: $timeout,
        );
    }
}
