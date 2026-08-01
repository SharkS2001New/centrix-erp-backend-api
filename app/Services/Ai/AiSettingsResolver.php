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
     * @return array{enabled: bool, api_key: string, model: string, base_url: string}|null
     */
    public static function resolveRuntimeForOrganization(Organization $organization): ?array
    {
        $gate = (new CapabilityGate)->forOrganization($organization);
        if (! $gate->aiPlatformEnabled()) {
            return null;
        }

        return self::buildRuntimeFromSettings(self::forOrganization($organization));
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
        $provider = (string) ($out['provider'] ?? 'openai');
        $out['provider'] = in_array($provider, ['openai'], true) ? $provider : 'openai';
        foreach (['model', 'api_key', 'base_url'] as $key) {
            $out[$key] = trim((string) ($out[$key] ?? ''));
        }
        unset($out['use_platform_key']);
        $out['insights'] = self::normalizeInsights(
            is_array($settings['insights'] ?? null) ? $settings['insights'] : [],
            is_array($defaults['insights'] ?? null) ? $defaults['insights'] : [],
        );

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
        $settings = self::forOrganization($organization);
        if (! ($settings['enabled'] ?? false)) {
            return false;
        }
        $insights = $settings['insights'] ?? [];

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
     * Runtime credentials for the platform AI training console (independent of tenant AI settings).
     *
     * @return array{enabled: bool, api_key: string, model: string, base_url: string}|null
     */
    public static function resolveRuntimeForPlatformTraining(): ?array
    {
        $settings = self::forPlatformTraining();
        if ($settings['enabled'] ?? false) {
            return self::buildRuntimeFromSettings($settings);
        }

        $envKey = trim((string) config('ai.platform_training.api_key', ''));
        if ($envKey === '') {
            return null;
        }

        return self::buildRuntimeFromSettings([
            'enabled' => true,
            'api_key' => $envKey,
            'model' => config('ai.platform_training.model'),
            'base_url' => config('ai.platform_training.base_url'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array{enabled: bool, api_key: string, model: string, base_url: string}|null
     */
    protected static function buildRuntimeFromSettings(array $settings): ?array
    {
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        $apiKey = trim((string) ($settings['api_key'] ?? ''));
        if ($apiKey === '') {
            return null;
        }

        $model = trim((string) ($settings['model'] ?? ''));
        if ($model === '') {
            $model = (string) config('ai.defaults.model', 'gpt-4o-mini');
        }

        $baseUrl = trim((string) ($settings['base_url'] ?? ''));
        if ($baseUrl === '') {
            $baseUrl = (string) config('ai.defaults.base_url', 'https://api.openai.com/v1');
        }
        $baseUrl = rtrim($baseUrl, '/');
        if (str_ends_with($baseUrl, '/v1/v1')) {
            $baseUrl = preg_replace('#/v1/v1$#', '/v1', $baseUrl) ?? $baseUrl;
        }

        return [
            'enabled' => true,
            'api_key' => $apiKey,
            'model' => $model,
            'base_url' => $baseUrl,
        ];
    }

    /** @return array<string, mixed> */
    public static function describePlatformTraining(): array
    {
        $settings = self::maskForClient(self::forPlatformTraining());
        $runtime = self::resolveRuntimeForPlatformTraining();

        return [
            'scope' => 'platform_training',
            'settings' => $settings,
            'available' => $runtime !== null,
            'model' => $runtime['model'] ?? ($settings['model'] ?: config('ai.defaults.model')),
            'provider' => $settings['provider'] ?? 'openai',
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
        $settings = self::maskForClient(self::forOrganization($organization));
        $runtime = $gate->aiPlatformEnabled() ? self::resolveRuntimeForOrganization($organization) : null;

        return [
            'settings' => $settings,
            'platform_enabled' => $gate->aiPlatformEnabled(),
            'available' => $runtime !== null,
            'model' => $runtime['model'] ?? ($settings['model'] ?: config('ai.defaults.model')),
            'provider' => $settings['provider'] ?? 'openai',
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

        return [
            'platform_enabled' => $gate->aiPlatformEnabled(),
            'enabled' => $gate->aiPlatformEnabled() && (bool) ($settings['enabled'] ?? false),
            'available' => $gate->aiPlatformEnabled() && self::isAvailableForOrganization($org),
            'insights' => $settings['insights'] ?? self::normalizeInsights([]),
        ];
    }
}
