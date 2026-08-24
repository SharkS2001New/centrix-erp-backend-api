<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class AiSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.ai', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $custom = $organization->module_settings['ai'] ?? [];

        return self::normalize(array_merge(self::defaults(), is_array($custom) ? $custom : []));
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        $org = $gate->organization();
        if (! $org) {
            return self::normalize(self::defaults());
        }

        return self::forOrganization($org);
    }

    /** @return array<string, mixed> */
    public static function forUser(User $user): array
    {
        $org = Organization::find($user->organization_id);

        return $org ? self::forOrganization($org) : self::normalize(self::defaults());
    }

    public static function resolveRuntime(User $user): ?array
    {
        $org = Organization::find($user->organization_id);
        if (! $org) {
            return null;
        }

        return self::resolveRuntimeForOrganization($org);
    }

    /**
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    public static function resolveRuntimeForOrganization(Organization $organization): ?array
    {
        if (! filter_var(config('ai.enabled', true), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        $gate = (new CapabilityGate)->forOrganization($organization);
        if (! $gate->aiPlatformEnabled()) {
            return null;
        }

        $settings = self::forOrganization($organization);
        $platformOffersFree = ! empty($settings['use_platform_gemini']);
        $prefersPlatform = self::orgPrefersPlatformAi($settings);

        if ($prefersPlatform && $platformOffersFree) {
            $runtime = self::buildRuntimeFromPlatformFreeAi($settings);
            if ($runtime) {
                return $runtime;
            }
        }

        $orgKey = trim((string) ($settings['api_key'] ?? ''));
        if ($orgKey !== '') {
            return self::buildRuntimeFromOrgCredentials($settings);
        }

        return null;
    }

    /**
     * Tenant choice: use platform free AI when offered (org admin checkbox).
     * Legacy: platform offers free AI and no org key → platform; org key without explicit choice → org key.
     */
    public static function orgPrefersPlatformAi(array $settings): bool
    {
        if (array_key_exists('use_platform_ai', $settings)) {
            return (bool) $settings['use_platform_ai'];
        }

        $platformOffers = ! empty($settings['use_platform_gemini']);
        $hasOrgKey = trim((string) ($settings['api_key'] ?? '')) !== '';

        return $platformOffers && ! $hasOrgKey;
    }

    public static function orgUsesPlatformRuntime(Organization $organization): bool
    {
        $settings = self::forOrganization($organization);

        return self::orgPrefersPlatformAi($settings) && ! empty($settings['use_platform_gemini']);
    }

    /**
     * Provider the platform offers free to selected orgs. Default: gemini.
     */
    public static function platformFreeAiProvider(): string
    {
        $training = self::forPlatformTraining();
        $provider = strtolower(trim((string) ($training['free_ai_provider'] ?? '')));
        if (! in_array($provider, ['gemini', 'openai'], true)) {
            $provider = strtolower(trim((string) config('ai.free_provider', 'gemini')));
        }

        return in_array($provider, ['gemini', 'openai'], true) ? $provider : 'gemini';
    }

    /**
     * Provider that will actually run (saved keys win over the radio).
     * If the preferred provider has no key, use the other configured provider.
     */
    public static function effectivePlatformFreeAiProvider(): string
    {
        $credentials = self::resolvePlatformFreeAiCredentials();

        return $credentials['provider'] ?? self::platformFreeAiProvider();
    }

    /**
     * True when any platform provider has credentials (Gemini or OpenAI-compatible).
     */
    public static function platformFreeAiConfigured(): bool
    {
        return self::resolvePlatformFreeAiCredentials() !== null;
    }

    /**
     * @return array{api_key: string, model: string, base_url: string, provider: string}|null
     */
    public static function resolvePlatformFreeAiCredentials(?string $provider = null): ?array
    {
        $preferred = $provider ?? self::platformFreeAiProvider();
        $preferredCreds = self::credentialsForProvider($preferred);
        if ($preferredCreds) {
            return $preferredCreds;
        }

        // Radio is only a preference. If Gemini is already saved (or OpenAI), use that.
        $fallback = $preferred === 'openai' ? 'gemini' : 'openai';

        return self::credentialsForProvider($fallback);
    }

    /**
     * @return array{api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function credentialsForProvider(string $provider): ?array
    {
        if ($provider === 'openai') {
            $openai = self::resolvePlatformOpenAiCredentials();

            return $openai ? array_merge($openai, ['provider' => 'openai']) : null;
        }

        $gemini = self::resolvePlatformGeminiCredentials();

        return $gemini ? array_merge($gemini, ['provider' => 'gemini']) : null;
    }

    /**
     * Gemini credentials owned by platform admin (PLATFORM org), with env fallback.
     *
     * @return array{api_key: string, model: string, base_url: string}|null
     */
    public static function resolvePlatformGeminiCredentials(): ?array
    {
        $training = self::forPlatformTraining();
        $apiKey = trim((string) ($training['gemini_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) config('ai.gemini.api_key', ''));
        }
        if ($apiKey === '') {
            return null;
        }

        $model = self::normalizeGeminiModel(trim((string) ($training['gemini_model'] ?? '')));

        $baseUrl = trim((string) ($training['gemini_base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = (string) config('ai.gemini.base_url');
        }

        return [
            'api_key' => $apiKey,
            'model' => $model,
            'base_url' => rtrim($baseUrl, '/'),
        ];
    }

    /**
     * OpenAI credentials owned by platform admin, with env fallback.
     *
     * @return array{api_key: string, model: string, base_url: string}|null
     */
    public static function resolvePlatformOpenAiCredentials(): ?array
    {
        $training = self::forPlatformTraining();
        $apiKey = trim((string) ($training['api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = trim((string) config('ai.platform_training.api_key', ''));
        }
        if ($apiKey === '') {
            return null;
        }

        $model = trim((string) ($training['model'] ?? ''));
        $baseUrl = trim((string) ($training['base_url'] ?? ''));

        return self::resolveOpenAiCompatibleConfig(
            $apiKey,
            $model !== '' ? $model : (string) config('ai.platform_training.model', ''),
            $baseUrl !== '' ? $baseUrl : (string) config('ai.platform_training.base_url', ''),
        );
    }

    public static function platformGeminiConfigured(): bool
    {
        return self::resolvePlatformGeminiCredentials() !== null;
    }

    /**
     * Normalize an OpenAI-compatible chat base URL (OpenAI, Groq, Together, OpenRouter, …).
     * Blank stays blank so callers can fall back to the OpenAI default.
     */
    public static function normalizeOpenAiBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        if (str_ends_with($baseUrl, '/v1/v1')) {
            $baseUrl = preg_replace('#/v1/v1$#', '/v1', $baseUrl) ?? $baseUrl;
        }

        return $baseUrl;
    }

    /**
     * Resolve OpenAI-compatible provider settings.
     * Base URL defaults to OpenAI unless the operator sets another OpenAI-compatible endpoint
     * (e.g. Groq https://api.groq.com/openai/v1). Key + base URL (+ model) is enough.
     *
     * @return array{api_key: string, model: string, base_url: string}
     */
    public static function resolveOpenAiCompatibleConfig(string $apiKey, string $model = '', string $baseUrl = ''): array
    {
        $apiKey = trim($apiKey);
        $model = trim($model);
        $baseUrl = self::normalizeOpenAiBaseUrl($baseUrl);

        if ($baseUrl === '') {
            $baseUrl = rtrim((string) config('ai.defaults.base_url', 'https://api.openai.com/v1'), '/');
        }

        if ($model === '') {
            $model = (string) config('ai.defaults.model', 'gpt-4o-mini');
        }

        return [
            'api_key' => $apiKey,
            'model' => $model,
            'base_url' => rtrim($baseUrl, '/'),
        ];
    }

    /**
     * Infer provider from common API key prefixes (AQ./AIza → Gemini, sk-/gsk_ → OpenAI-compatible).
     */
    public static function inferProviderFromApiKey(string $apiKey): ?string
    {
        $key = trim($apiKey);
        if ($key === '') {
            return null;
        }
        if (str_starts_with($key, 'AQ.') || str_starts_with($key, 'AIza')) {
            return 'gemini';
        }
        // OpenAI-compatible keys (OpenAI sk-, Groq gsk_, etc.) use the OpenAI client + base URL.
        if (str_starts_with($key, 'sk-') || str_starts_with($key, 'gsk_')) {
            return 'openai';
        }

        return null;
    }

    /**
     * Map retired Gemini model ids to the current default (Generative Language API).
     */
    public static function normalizeGeminiModel(string $model): string
    {
        $model = trim($model);
        $default = (string) config('ai.gemini.model', 'gemini-3.6-flash');
        if ($model === '') {
            return $default;
        }

        static $retired = [
            'gemini-2.0-flash',
            'gemini-2.0-flash-lite',
            'gemini-2.0-flash-thinking-exp',
            'gemini-2.5-flash',
            'gemini-2.5-flash-lite',
            'gemini-1.5-flash',
            'gemini-1.5-flash-latest',
            'gemini-1.5-pro',
            'gemini-1.5-pro-latest',
            'gemini-3.7-flash',
        ];

        if (in_array($model, $retired, true)) {
            return $default;
        }

        if (preg_match('/^gemini-2\.0-(flash|pro)/', $model)) {
            return $default;
        }

        return $model;
    }

    /**
     * @param  array<string, mixed>  $orgSettings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function buildRuntimeFromPlatformFreeAi(array $orgSettings): ?array
    {
        $credentials = self::resolvePlatformFreeAiCredentials();
        if (! $credentials) {
            return null;
        }

        $model = trim((string) ($orgSettings['model'] ?? ''));
        if ($model === '') {
            $model = $credentials['model'];
        }
        if (($credentials['provider'] ?? '') === 'gemini') {
            $model = self::normalizeGeminiModel($model);
        }

        return [
            'enabled' => true,
            'provider' => $credentials['provider'],
            'api_key' => $credentials['api_key'],
            'model' => $model,
            'base_url' => $credentials['base_url'],
        ];
    }

    /**
     * @deprecated Use buildRuntimeFromPlatformFreeAi()
     * @param  array<string, mixed>  $orgSettings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function buildRuntimeFromPlatformGemini(array $orgSettings): ?array
    {
        return self::buildRuntimeFromPlatformFreeAi($orgSettings);
    }

    public static function isAvailableForUser(User $user): bool
    {
        return self::resolveRuntime($user) !== null;
    }

    public static function isAvailableForOrganization(Organization $organization): bool
    {
        return self::resolveRuntimeForOrganization($organization) !== null;
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);
        $out['enabled'] = (bool) ($out['enabled'] ?? false);
        $out['use_platform_gemini'] = (bool) ($out['use_platform_gemini'] ?? false);
        $out['use_platform_ai'] = (bool) ($out['use_platform_ai'] ?? false);
        $freeProvider = strtolower(trim((string) ($out['free_ai_provider'] ?? config('ai.free_provider', 'gemini'))));
        if ($freeProvider === 'ollama') {
            $freeProvider = 'gemini';
        }
        $out['free_ai_provider'] = in_array($freeProvider, ['gemini', 'openai'], true) ? $freeProvider : 'gemini';
        $provider = strtolower(trim((string) ($out['provider'] ?? config('ai.provider', 'openai'))));
        if ($provider === 'ollama') {
            $provider = 'openai';
        }
        $allowed = ['openai', 'gemini'];
        $out['provider'] = in_array($provider, $allowed, true) ? $provider : 'openai';
        foreach (['model', 'api_key', 'base_url', 'gemini_api_key', 'gemini_model', 'gemini_base_url'] as $key) {
            $out[$key] = trim((string) ($out[$key] ?? ''));
        }
        unset($out['ollama_base_url'], $out['ollama_model'], $out['ollama_api_key']);
        unset($out['use_platform_key']);
        $out['insights'] = self::normalizeInsights(
            is_array($settings['insights'] ?? null) ? $settings['insights'] : [],
            is_array($defaults['insights'] ?? null) ? $defaults['insights'] : [],
        );
        $out['tools'] = self::normalizeTools(
            is_array($settings['tools'] ?? null) ? $settings['tools'] : (is_array($out['tools'] ?? null) ? $out['tools'] : []),
            is_array($defaults['tools'] ?? null) ? $defaults['tools'] : config('ai.tools', []),
        );

        return $out;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $defaults
     * @return array<string, bool>
     */
    public static function normalizeTools(array $incoming, array $defaults = []): array
    {
        $base = $defaults !== [] ? $defaults : config('ai.tools', []);
        $out = [];
        foreach ($base as $name => $enabled) {
            $out[(string) $name] = array_key_exists($name, $incoming)
                ? (bool) $incoming[$name]
                : (bool) $enabled;
        }
        foreach ($incoming as $name => $enabled) {
            $key = (string) $name;
            if ($key !== '' && ! array_key_exists($key, $out)) {
                $out[$key] = (bool) $enabled;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $defaults
     * @return array<string, mixed>
     */
    public static function normalizeInsights(array $incoming, array $defaults = []): array
    {
        if ($defaults === []) {
            $defaults = self::defaults()['insights'] ?? [];
        }
        $merged = array_replace_recursive($defaults, $incoming);

        $merged['enabled'] = (bool) ($merged['enabled'] ?? true);
        $channels = is_array($merged['channels'] ?? null) ? $merged['channels'] : [];
        $merged['channels'] = [
            'email' => (bool) ($channels['email'] ?? true),
            'whatsapp' => (bool) ($channels['whatsapp'] ?? false),
            'sms' => (bool) ($channels['sms'] ?? false),
        ];

        $recipients = is_array($merged['recipients'] ?? null) ? $merged['recipients'] : [];
        $merged['recipients'] = [
            'emails' => self::normalizeStringList($recipients['emails'] ?? []),
            'phones' => self::normalizeStringList($recipients['phones'] ?? []),
            'whatsapp_phones' => self::normalizeStringList($recipients['whatsapp_phones'] ?? []),
        ];

        foreach (AiInsightCatalog::scheduledTypes() as $briefKey) {
            $brief = is_array($merged[$briefKey] ?? null) ? $merged[$briefKey] : [];
            $def = AiInsightCatalog::definitions()[$briefKey] ?? [];
            $time = preg_match('/^\d{2}:\d{2}$/', (string) ($brief['schedule_time'] ?? ''))
                ? (string) $brief['schedule_time']
                : (string) ($defaults[$briefKey]['schedule_time'] ?? ($def['default_time'] ?? '07:00'));
            $merged[$briefKey] = [
                'enabled' => (bool) ($brief['enabled'] ?? false),
                'schedule_time' => $time,
                'lookback_days' => max(1, min(90, (int) ($brief['lookback_days'] ?? ($defaults[$briefKey]['lookback_days'] ?? ($def['default_lookback'] ?? 7))))),
            ];
        }

        $alerts = is_array($merged['exception_alerts'] ?? null) ? $merged['exception_alerts'] : [];
        $merged['exception_alerts'] = [
            'enabled' => (bool) ($alerts['enabled'] ?? false),
            'low_stock' => (bool) ($alerts['low_stock'] ?? true),
            'unpaid_spike' => (bool) ($alerts['unpaid_spike'] ?? true),
            'unusual_discounts' => (bool) ($alerts['unusual_discounts'] ?? true),
            'void_cancel_bursts' => (bool) ($alerts['void_cancel_bursts'] ?? true),
        ];

        return $merged;
    }

    /** @param  mixed  $value
     * @return list<string>
     */
    protected static function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $trimmed = trim((string) $item);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }

        return array_values(array_unique($out));
    }

    /** @return array<string, mixed> */
    public static function insightsForOrganization(Organization $organization): array
    {
        return self::forOrganization($organization)['insights'] ?? self::normalizeInsights([]);
    }

    public static function insightsEnabled(Organization $organization): bool
    {
        $gate = (new CapabilityGate)->forOrganization($organization);
        if (! $gate->aiPlatformEnabled()) {
            return false;
        }

        $insights = self::forOrganization($organization)['insights'] ?? [];

        return (bool) ($insights['enabled'] ?? true) && self::isAvailableForOrganization($organization);
    }

    public static function platformOrganization(bool $refresh = false): ?Organization
    {
        static $organization = null;
        static $resolved = false;

        if ($refresh) {
            $resolved = false;
            $organization = null;
        }

        if ($resolved) {
            return $organization;
        }

        $resolved = true;
        $organization = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->first();

        return $organization;
    }

    /** @return array<string, mixed> */
    public static function forPlatformTraining(): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            return self::normalize([]);
        }

        $custom = $org->module_settings['platform_ai_training'] ?? [];

        return self::normalize(is_array($custom) ? $custom : []);
    }

    /**
     * Runtime credentials for platform-admin tools (email assist, training console, train-from-usage).
     * Prefer the selected free provider when that key exists; otherwise use any stored Gemini/OpenAI key
     * even if "Enable platform AI tools" is still off.
     *
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    public static function resolveRuntimeForPlatformTraining(): ?array
    {
        $settings = self::forPlatformTraining();
        $preferred = self::platformFreeAiProvider();

        $preferredRuntime = $preferred === 'gemini'
            ? self::platformGeminiRuntime()
            : self::platformOpenAiRuntime($settings);
        if ($preferredRuntime) {
            return $preferredRuntime;
        }

        $fallback = $preferred === 'gemini'
            ? self::platformOpenAiRuntime($settings)
            : self::platformGeminiRuntime();
        if ($fallback) {
            return $fallback;
        }

        $envKey = trim((string) config('ai.platform_training.api_key', ''));
        if ($envKey === '') {
            return null;
        }

        return self::buildRuntimeFromOrgCredentials([
            'enabled' => true,
            'provider' => 'openai',
            'api_key' => $envKey,
            'model' => config('ai.platform_training.model'),
            'base_url' => config('ai.platform_training.base_url'),
        ]);
    }

    /**
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function platformGeminiRuntime(): ?array
    {
        $credentials = self::resolvePlatformGeminiCredentials();
        if (! $credentials) {
            return null;
        }

        return [
            'enabled' => true,
            'provider' => 'gemini',
            'api_key' => $credentials['api_key'],
            'model' => $credentials['model'],
            'base_url' => $credentials['base_url'],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function platformOpenAiRuntime(array $settings): ?array
    {
        return self::buildRuntimeFromOrgCredentials(array_merge($settings, [
            'enabled' => true,
            'provider' => 'openai',
        ]));
    }

    /**
     * Tenant-owned credentials only — no silent platform/env fallback.
     *
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string, provider: string}|null
     */
    protected static function buildRuntimeFromOrgCredentials(array $settings): ?array
    {
        $provider = strtolower(trim((string) ($settings['provider'] ?? 'openai')));
        if (! in_array($provider, ['openai', 'gemini'], true)) {
            $provider = 'openai';
        }

        $apiKey = trim((string) ($settings['api_key'] ?? ''));

        if ($apiKey === '') {
            return null;
        }

        $inferred = self::inferProviderFromApiKey($apiKey);
        if ($inferred !== null) {
            $provider = $inferred;
        }

        $model = trim((string) ($settings['model'] ?? ''));
        $baseUrl = trim((string) ($settings['base_url'] ?? ''));

        if ($provider === 'gemini') {
            if ($model === '') {
                $model = (string) config('ai.gemini.model', 'gemini-3.6-flash');
            }
            $model = self::normalizeGeminiModel($model);
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

        $resolved = self::resolveOpenAiCompatibleConfig($apiKey, $model, $baseUrl);

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
    protected static function buildRuntimeFromSettings(array $settings): ?array
    {
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        return self::buildRuntimeFromOrgCredentials($settings);
    }

    /** @return array<string, mixed> */
    public static function describePlatformTraining(): array
    {
        $settings = self::maskForClient(self::forPlatformTraining());
        $runtime = self::resolveRuntimeForPlatformTraining();
        $gemini = self::resolvePlatformGeminiCredentials();
        $preferredProvider = self::platformFreeAiProvider();
        $effectiveProvider = self::effectivePlatformFreeAiProvider();
        $freeConfigured = self::platformFreeAiConfigured();

        return [
            'scope' => 'platform_training',
            'settings' => $settings,
            'available' => $runtime !== null,
            'gemini_available' => $gemini !== null,
            'free_ai_provider' => $preferredProvider,
            'effective_free_ai_provider' => $effectiveProvider,
            'free_ai_configured' => $freeConfigured,
            'model' => $runtime['model']
                ?? (($settings['model'] ?? '') !== '' ? $settings['model'] : config('ai.defaults.model')),
            'provider' => $runtime['provider']
                ?? ($effectiveProvider === 'gemini' ? 'gemini' : ($settings['provider'] ?? 'openai')),
            'tools_provider' => $runtime['provider'] ?? $effectiveProvider,
            'tools_available' => $runtime !== null,
            'gemini_model' => (is_array($gemini) ? ($gemini['model'] ?? '') : '')
                ?: (($settings['gemini_model'] ?? '') !== '' ? $settings['gemini_model'] : config('ai.gemini.model')),
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function savePlatformTraining(array $incoming): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = is_array($moduleSettings['platform_ai_training'] ?? null)
            ? $moduleSettings['platform_ai_training']
            : [];
        $moduleSettings['platform_ai_training'] = self::mergeStored($current, $incoming);
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describePlatformTraining();
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeStored(array $current, array $incoming): array
    {
        $mergedIncoming = $incoming;
        if (array_key_exists('insights', $incoming) && is_array($incoming['insights'])) {
            $mergedIncoming['insights'] = array_replace_recursive(
                is_array($current['insights'] ?? null) ? $current['insights'] : [],
                $incoming['insights'],
            );
        }

        $next = self::normalize(array_merge($current, $mergedIncoming));

        if (array_key_exists('api_key', $incoming)) {
            $key = trim((string) $incoming['api_key']);
            if ($key === '' || str_starts_with($key, '••••')) {
                $next['api_key'] = trim((string) ($current['api_key'] ?? ''));
            }
        }

        if (array_key_exists('gemini_api_key', $incoming)) {
            $key = trim((string) $incoming['gemini_api_key']);
            if ($key === '' || str_starts_with($key, '••••')) {
                $next['gemini_api_key'] = trim((string) ($current['gemini_api_key'] ?? ''));
            }
        }

        return $next;
    }

    /** @param  array<string, mixed>  $settings */
    public static function maskForClient(array $settings): array
    {
        $out = self::normalize($settings);
        $key = $out['api_key'] ?? '';
        unset($out['api_key']);
        $out['api_key_set'] = $key !== '';
        $out['api_key_hint'] = $key !== '' ? '••••'.substr($key, -4) : '';

        $geminiKey = $out['gemini_api_key'] ?? '';
        unset($out['gemini_api_key']);
        $out['gemini_api_key_set'] = $geminiKey !== '';
        $out['gemini_api_key_hint'] = $geminiKey !== '' ? '••••'.substr($geminiKey, -4) : '';

        return $out;
    }

    /** @return array<string, mixed> */
    public static function describeForClient(User $user): array
    {
        $org = Organization::find($user->organization_id);

        return $org
            ? self::describeForOrganization($org)
            : self::describeForOrganization(new Organization);
    }

    /** @return array<string, mixed> */
    public static function describeForOrganization(Organization $organization): array
    {
        $gate = (new CapabilityGate)->forOrganization($organization);
        $raw = self::forOrganization($organization);
        $settings = self::maskForClient($raw);
        $runtime = $gate->aiPlatformEnabled() ? self::resolveRuntimeForOrganization($organization) : null;
        $hasOrgKey = trim((string) ($raw['api_key'] ?? '')) !== '';
        $platformOffersFree = (bool) ($raw['use_platform_gemini'] ?? false);
        $prefersPlatform = self::orgPrefersPlatformAi($raw);
        $freeProvider = self::effectivePlatformFreeAiProvider();
        $credentialSource = null;
        if ($runtime) {
            if ($prefersPlatform && $platformOffersFree) {
                $credentialSource = match ($runtime['provider'] ?? $freeProvider) {
                    'openai' => 'platform_openai',
                    default => 'platform_gemini',
                };
            } elseif ($hasOrgKey) {
                $credentialSource = 'org';
            }
        }

        return [
            'settings' => $settings,
            'platform_enabled' => $gate->aiPlatformEnabled(),
            'available' => $runtime !== null,
            'use_platform_gemini' => $platformOffersFree,
            'use_platform_ai' => $prefersPlatform,
            'platform_offers_free_ai' => $platformOffersFree,
            'free_ai_provider' => $freeProvider,
            'platform_gemini_configured' => self::platformGeminiConfigured(),
            'platform_free_ai_configured' => self::platformFreeAiConfigured(),
            'has_org_api_key' => $hasOrgKey,
            'credential_source' => $credentialSource,
            'model' => $runtime['model'] ?? (
                ($settings['model'] ?? '') !== ''
                    ? $settings['model']
                    : (
                        ($runtime['provider'] ?? $settings['provider'] ?? '') === 'gemini' || ($prefersPlatform && $freeProvider === 'gemini')
                            ? config('ai.gemini.model')
                            : config('ai.defaults.model')
                    )
            ),
            'provider' => $runtime['provider'] ?? ($settings['provider'] ?? config('ai.provider', 'openai')),
        ];
    }

    /** @return array<string, mixed> */
    public static function clientCapabilities(CapabilityGate $gate): array
    {
        $org = $gate->organization();
        if (! $org) {
            return ['enabled' => false, 'available' => false];
        }

        $settings = self::forOrganization($org);
        $platformOffersFree = (bool) ($settings['use_platform_gemini'] ?? false);
        $prefersPlatform = self::orgPrefersPlatformAi($settings);
        $available = $gate->aiPlatformEnabled() && self::isAvailableForOrganization($org);
        $hasOrgKey = trim((string) ($settings['api_key'] ?? '')) !== '';
        $freeProvider = self::effectivePlatformFreeAiProvider();
        $credentialSource = null;
        if ($available) {
            if ($prefersPlatform && $platformOffersFree) {
                $credentialSource = match ($freeProvider) {
                    'openai' => 'platform_openai',
                    default => 'platform_gemini',
                };
            } elseif ($hasOrgKey) {
                $credentialSource = 'org';
            }
        }

        return [
            'platform_enabled' => $gate->aiPlatformEnabled(),
            'enabled' => $gate->aiPlatformEnabled(),
            'available' => $available,
            'use_platform_gemini' => $platformOffersFree,
            'use_platform_ai' => $prefersPlatform,
            'platform_offers_free_ai' => $platformOffersFree,
            'free_ai_provider' => $freeProvider,
            'credential_source' => $credentialSource,
            'insights' => $settings['insights'] ?? self::normalizeInsights([]),
            'tools' => $settings['tools'] ?? self::normalizeTools([]),
        ];
    }
}
