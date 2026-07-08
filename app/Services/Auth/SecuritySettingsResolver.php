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

    public static function tokenExpirationMinutesForChannel(string $loginChannel): ?int
    {
        $channel = match ($loginChannel) {
            UserLoginChannelService::MOBILE, UserLoginChannelService::MANAGER => UserLoginChannelService::MOBILE,
            UserLoginChannelService::POS => UserLoginChannelService::POS,
            UserLoginChannelService::BACKOFFICE => UserLoginChannelService::BACKOFFICE,
            default => UserLoginChannelService::BACKOFFICE,
        };

        $byChannel = config('security.token_expiration_minutes_by_channel', []);
        $minutes = (int) ($byChannel[$channel] ?? config('security.sanctum_token_expiration_minutes', 60 * 24));

        return $minutes > 0 ? $minutes : null;
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $fallback = max(1, (int) config('erp.session_idle_minutes', 60));
        $lockFallback = max(1, (int) config('erp.screen_lock_minutes', 5));
        $out['session_idle_minutes'] = max(5, min(480, (int) ($out['session_idle_minutes'] ?? $fallback)));
        $out['screen_lock_minutes'] = max(1, min(120, (int) ($out['screen_lock_minutes'] ?? $lockFallback)));
        if ($out['screen_lock_minutes'] >= $out['session_idle_minutes']) {
            $out['screen_lock_minutes'] = max(1, $out['session_idle_minutes'] - 1);
        }
        $out['require_strong_passwords'] = (bool) ($out['require_strong_passwords'] ?? false);
        $out['password_min_length'] = max(6, min(128, (int) ($out['password_min_length'] ?? 8)));
        $out['password_expiry_enabled'] = (bool) ($out['password_expiry_enabled'] ?? false);
        $out['password_expiry_days'] = max(30, min(730, (int) ($out['password_expiry_days'] ?? 90)));
        $out['password_expiry_max_skips'] = max(0, min(10, (int) ($out['password_expiry_max_skips'] ?? 2)));

        return $out;
    }
}
