<?php

namespace App\Services\Ai;

use App\Exceptions\Ai\AiProviderException;
use App\Models\Organization;

class AiCredentialTestService
{
    public function __construct(protected AiProviderFactory $providers) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{body: array<string, mixed>, status: int}
     */
    public function testForOrganization(Organization $org, array $data): array
    {
        $settings = AiSettingsResolver::forOrganization($org);
        $usePlatform = array_key_exists('use_platform_ai', $data)
            ? (bool) $data['use_platform_ai']
            : AiSettingsResolver::orgPrefersPlatformAi($settings);

        $provider = strtolower(trim((string) ($data['provider'] ?? ($settings['provider'] ?? 'openai'))));
        if (! in_array($provider, ['gemini', 'openai'], true)) {
            $provider = 'openai';
        }

        $runtime = $this->runtimeForOrgTest($org, $provider, $data, $usePlatform);

        return $this->executeTest($runtime, $provider);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{body: array<string, mixed>, status: int}
     */
    public function testForPlatform(string $provider, array $data): array
    {
        $runtime = $this->runtimeForPlatformTest($provider, $data);

        return $this->executeTest($runtime, $provider);
    }

    /**
     * @param  array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null  $runtime
     * @return array{body: array<string, mixed>, status: int}
     */
    protected function executeTest(?array $runtime, string $provider): array
    {
        if (! $runtime) {
            return [
                'body' => [
                    'ok' => false,
                    'provider' => $provider,
                    'message' => match ($provider) {
                        'gemini' => 'No Gemini API key configured. Paste a key and save, or include it in this test.',
                        default => 'No OpenAI API key configured. Paste a key and save, or include it in this test.',
                    },
                ],
                'status' => 422,
            ];
        }

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => 'You are a connectivity check for Centrix ERP. Reply in one short sentence.',
                'messages' => [
                    ['role' => 'user', 'content' => 'Say hello to Centrix ERP'],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 256,
                'thinking_level' => 'MINIMAL',
            ]);
        } catch (AiProviderException $e) {
            return [
                'body' => [
                    'ok' => false,
                    'provider' => $runtime['provider'],
                    'model' => $runtime['model'],
                    'endpoint' => $runtime['base_url'] ?? null,
                    'error_code' => $e->codeKey,
                    'message' => $e->getMessage(),
                ],
                'status' => 422,
            ];
        } catch (\Throwable $e) {
            return [
                'body' => [
                    'ok' => false,
                    'provider' => $runtime['provider'],
                    'model' => $runtime['model'],
                    'endpoint' => $runtime['base_url'] ?? null,
                    'message' => 'Could not reach the AI provider.',
                ],
                'status' => 422,
            ];
        }

        $reply = trim((string) ($turn['text'] ?? ''));
        if (strlen($reply) > 240) {
            $reply = substr($reply, 0, 237).'…';
        }

        return [
            'body' => [
                'ok' => true,
                'provider' => $runtime['provider'],
                'model' => $runtime['model'],
                'endpoint' => $runtime['base_url'] ?? null,
                'reply' => $reply !== '' ? $reply : 'Connected successfully.',
                'message' => 'Connection successful.',
            ],
            'status' => 200,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected function runtimeForOrgTest(Organization $org, string $provider, array $data, bool $usePlatform): ?array
    {
        if ($usePlatform) {
            $credentials = AiSettingsResolver::resolvePlatformFreeAiCredentials($provider);
            if (! $credentials) {
                return null;
            }

            return array_merge($credentials, [
                'enabled' => true,
                'provider' => $provider,
            ]);
        }

        $settings = AiSettingsResolver::forOrganization($org);
        $draftKey = trim((string) ($data['api_key'] ?? ''));
        if ($draftKey !== '' && ! str_starts_with($draftKey, '••••')) {
            if ($provider === 'gemini') {
                $model = trim((string) ($data['model'] ?? ''));
                if ($model === '') {
                    $model = (string) config('ai.gemini.model', 'gemini-3.6-flash');
                }
                $model = AiSettingsResolver::normalizeGeminiModel($model);
                $baseUrl = trim((string) ($data['base_url'] ?? ''));
                if ($baseUrl === '') {
                    $baseUrl = (string) config('ai.gemini.base_url');
                }

                return [
                    'enabled' => true,
                    'provider' => 'gemini',
                    'api_key' => $draftKey,
                    'model' => $model,
                    'base_url' => rtrim($baseUrl, '/'),
                ];
            }

            $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig(
                $draftKey,
                trim((string) ($data['model'] ?? '')),
                trim((string) ($data['base_url'] ?? '')),
            );

            return [
                'enabled' => true,
                'provider' => 'openai',
                'api_key' => $resolved['api_key'],
                'model' => $resolved['model'],
                'base_url' => $resolved['base_url'],
            ];
        }

        $savedKey = trim((string) ($settings['api_key'] ?? ''));
        if ($savedKey === '') {
            return null;
        }

        $merged = array_merge($settings, [
            'provider' => $provider,
        ]);
        if (trim((string) ($data['model'] ?? '')) !== '') {
            $merged['model'] = $data['model'];
        }
        if (trim((string) ($data['base_url'] ?? '')) !== '') {
            $merged['base_url'] = $data['base_url'];
        }

        return $this->buildRuntimeFromOrgSettings($merged);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected function runtimeForPlatformTest(string $provider, array $data): ?array
    {
        if ($provider === 'gemini') {
            $draftKey = trim((string) ($data['gemini_api_key'] ?? ''));
            if ($draftKey !== '' && ! str_starts_with($draftKey, '••••')) {
                $model = AiSettingsResolver::normalizeGeminiModel(trim((string) ($data['gemini_model'] ?? '')));

                return [
                    'enabled' => true,
                    'provider' => 'gemini',
                    'api_key' => $draftKey,
                    'model' => $model,
                    'base_url' => (string) config('ai.gemini.base_url'),
                ];
            }

            $credentials = AiSettingsResolver::resolvePlatformGeminiCredentials();
            if (! $credentials) {
                return null;
            }

            $modelOverride = trim((string) ($data['gemini_model'] ?? ''));
            if ($modelOverride !== '') {
                $credentials['model'] = AiSettingsResolver::normalizeGeminiModel($modelOverride);
            }

            return array_merge($credentials, [
                'enabled' => true,
                'provider' => 'gemini',
            ]);
        }

        $draftKey = trim((string) ($data['api_key'] ?? ''));
        if ($draftKey !== '' && ! str_starts_with($draftKey, '••••')) {
            $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig(
                $draftKey,
                trim((string) ($data['model'] ?? '')),
                trim((string) ($data['base_url'] ?? '')),
            );

            return [
                'enabled' => true,
                'provider' => 'openai',
                'api_key' => $resolved['api_key'],
                'model' => $resolved['model'],
                'base_url' => $resolved['base_url'],
            ];
        }

        $credentials = AiSettingsResolver::resolvePlatformOpenAiCredentials();
        if (! $credentials) {
            return null;
        }

        $modelOverride = trim((string) ($data['model'] ?? ''));
        $baseUrlOverride = trim((string) ($data['base_url'] ?? ''));
        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig(
            $credentials['api_key'],
            $modelOverride !== '' ? $modelOverride : $credentials['model'],
            $baseUrlOverride !== '' ? $baseUrlOverride : $credentials['base_url'],
        );

        return [
            'enabled' => true,
            'provider' => 'openai',
            'api_key' => $resolved['api_key'],
            'model' => $resolved['model'],
            'base_url' => $resolved['base_url'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected function buildRuntimeFromOrgSettings(array $settings): ?array
    {
        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        $provider = strtolower(trim((string) ($settings['provider'] ?? 'openai')));
        if (! in_array($provider, ['openai', 'gemini'], true)) {
            $provider = 'openai';
        }

        $inferred = AiSettingsResolver::inferProviderFromApiKey($apiKey);
        if ($inferred !== null) {
            $provider = $inferred;
        }

        $model = trim((string) ($settings['model'] ?? ''));
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));

        if ($provider === 'gemini') {
            if ($model === '') {
                $model = (string) config('ai.gemini.model', 'gemini-3.6-flash');
            }
            $model = AiSettingsResolver::normalizeGeminiModel($model);
            if ($baseUrl === '') {
                $baseUrl = (string) config('ai.gemini.base_url');
            }

            return [
                'enabled' => true,
                'provider' => 'gemini',
                'api_key' => $apiKey,
                'model' => $model,
                'base_url' => rtrim($baseUrl, '/'),
            ];
        }

        $resolved = AiSettingsResolver::resolveOpenAiCompatibleConfig($apiKey, $model, $baseUrl);

        return [
            'enabled' => true,
            'provider' => 'openai',
            'api_key' => $resolved['api_key'],
            'model' => $resolved['model'],
            'base_url' => $resolved['base_url'],
        ];
    }
}
