<?php

namespace App\Services\Auth;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class SecuritySettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.security', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['security'] ?? null)
            ? $organization->module_settings['security']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forOrganizationId(?int $organizationId): array
    {
        if (! $organizationId) {
            return self::normalize(self::defaults());
        }

        $organization = Organization::find($organizationId);

        return $organization
            ? self::forOrganization($organization)
            : self::normalize(self::defaults());
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('security'),
        ));
    }

    public static function sessionIdleMinutesForOrganizationId(?int $organizationId): int
    {
        return (int) self::forOrganizationId($organizationId)['session_idle_minutes'];
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $fallback = max(1, (int) config('erp.session_idle_minutes', 15));
        $out['session_idle_minutes'] = max(5, min(480, (int) ($out['session_idle_minutes'] ?? $fallback)));
        $out['require_strong_passwords'] = (bool) ($out['require_strong_passwords'] ?? false);
        $out['password_min_length'] = max(6, min(128, (int) ($out['password_min_length'] ?? 8)));

        return $out;
    }
}
