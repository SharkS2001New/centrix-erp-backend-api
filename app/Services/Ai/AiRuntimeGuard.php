<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Health + concurrency helpers for AI runtime.
 */
class AiRuntimeGuard
{
    public const CONCURRENCY_KEY = 'ai:concurrent_inflight';

    /**
     * Safe health payload for API / Platform Admin (no secrets / private paths).
     *
     * @return array{
     *   enabled: bool,
     *   provider: string,
     *   available: bool,
     *   model: string,
     *   status: string,
     *   detail: string
     * }
     */
    public function health(): array
    {
        $enabled = filter_var(config('ai.enabled', true), FILTER_VALIDATE_BOOLEAN);
        $provider = strtolower((string) config('ai.provider', 'openai'));
        $model = $this->configuredModel($provider);

        if (! $enabled) {
            return [
                'enabled' => false,
                'provider' => $provider,
                'available' => false,
                'model' => $model,
                'status' => 'OFFLINE',
                'detail' => 'AI is disabled in configuration.',
            ];
        }

        $configured = match ($provider) {
            'gemini' => trim((string) config('ai.gemini.api_key', '')) !== ''
                || AiSettingsResolver::platformGeminiConfigured(),
            'openai' => trim((string) config('ai.platform_training.api_key', '')) !== ''
                || AiSettingsResolver::platformFreeAiConfigured(),
            default => false,
        };

        return [
            'enabled' => true,
            'provider' => $provider,
            'available' => $configured,
            'model' => $model,
            'status' => $configured ? 'ONLINE' : 'DEGRADED',
            'detail' => $configured
                ? 'Provider credentials are configured.'
                : 'Provider credentials are not configured.',
        ];
    }

    /**
     * Acquire a concurrency slot. Returns false if the server is at capacity.
     * Set AI_MAX_CONCURRENT_REQUESTS=0 to disable the gate (unlimited concurrent).
     */
    public function acquire(): bool
    {
        $max = (int) config('ai.max_concurrent_requests', 32);
        if ($max <= 0) {
            return true;
        }

        $key = self::CONCURRENCY_KEY;

        try {
            $lock = Cache::lock('ai:concurrency:mutex', 5);
            try {
                $lock->block(2);
                $current = (int) Cache::get($key, 0);
                if ($current >= $max) {
                    return false;
                }
                Cache::put($key, $current + 1, now()->addMinutes(5));

                return true;
            } finally {
                optional($lock)->release();
            }
        } catch (\Throwable $e) {
            Log::warning('AI concurrency acquire failed; allowing request', ['message' => $e->getMessage()]);

            return true;
        }
    }

    public function release(): void
    {
        $key = self::CONCURRENCY_KEY;

        try {
            $lock = Cache::lock('ai:concurrency:mutex', 5);
            try {
                $lock->block(2);
                $current = (int) Cache::get($key, 0);
                Cache::put($key, max(0, $current - 1), now()->addMinutes(5));
            } finally {
                optional($lock)->release();
            }
        } catch (\Throwable $e) {
            Log::warning('AI concurrency release failed', ['message' => $e->getMessage()]);
        }
    }

    public function inflight(): int
    {
        try {
            return (int) Cache::get(self::CONCURRENCY_KEY, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    protected function configuredModel(string $provider): string
    {
        return match ($provider) {
            'gemini' => (string) config('ai.gemini.model', 'gemini-3.6-flash'),
            default => (string) config('ai.defaults.model', 'gpt-4o-mini'),
        };
    }
}
