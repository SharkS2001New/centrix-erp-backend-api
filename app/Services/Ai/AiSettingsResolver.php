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

        $settings = self::forOrganization($organization);
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
        $out = array_merge(self::defaults(), $settings);
        $out['enabled'] = (bool) ($out['enabled'] ?? false);
        $out['provider'] = in_array($out['provider'] ?? 'openai', ['openai'], true) ? $out['provider'] : 'openai';
        foreach (['model', 'api_key', 'base_url'] as $key) {
            $out[$key] = trim((string) ($out[$key] ?? ''));
        }
        unset($out['use_platform_key']);

        return $out;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeStored(array $current, array $incoming): array
    {
        $next = self::normalize(array_merge($current, $incoming));

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
        ];
    }
}
