<?php

namespace App\Services\WhatsApp;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsappConfig;
use App\Services\Erp\CapabilityGate;

class WhatsAppSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.whatsapp', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $custom = $organization->module_settings['whatsapp'] ?? [];

        return self::normalize(array_merge(self::defaults(), is_array($custom) ? $custom : []));
    }

    public static function configRow(Organization $organization): ?WhatsappConfig
    {
        return WhatsappConfig::query()
            ->where('organization_id', $organization->id)
            ->first();
    }

    /** @return array<string, mixed> */
    public static function describeForOrganization(Organization $organization): array
    {
        $gate = (new CapabilityGate)->forOrganization($organization);
        $settings = self::maskForClient(self::forOrganization($organization));
        $row = self::configRow($organization);
        $runtime = $gate->whatsappPlatformEnabled() ? self::resolveRuntimeForOrganization($organization) : null;

        return [
            'settings' => array_merge($settings, self::maskCredentialsForClient($row)),
            'platform_enabled' => $gate->whatsappPlatformEnabled(),
            'configured' => $runtime !== null,
            'webhook_url' => self::webhookUrl(),
            'bot_user' => self::describeBotUser($row?->bot_user_id),
        ];
    }

    /** @return array{enabled: bool, organization_id: int, phone_number_id: string, access_token: string, bot_user_id: int}|null */
    public static function resolveRuntimeForOrganization(Organization $organization): ?array
    {
        $gate = (new CapabilityGate)->forOrganization($organization);
        if (! $gate->whatsappPlatformEnabled()) {
            return null;
        }

        $settings = self::forOrganization($organization);
        if (! ($settings['enabled'] ?? false)) {
            return null;
        }

        $row = self::configRow($organization);
        if (! $row || ! $row->is_active) {
            return null;
        }

        $phoneNumberId = trim((string) ($row->phone_number_id ?? ''));
        $accessToken = trim((string) ($row->access_token ?? ''));
        $botUserId = (int) ($row->bot_user_id ?? 0);

        if ($phoneNumberId === '' || $accessToken === '' || $botUserId <= 0) {
            return null;
        }

        $botUser = User::query()->find($botUserId);
        if (! $botUser || (int) $botUser->organization_id !== (int) $organization->id) {
            return null;
        }

        return [
            'enabled' => true,
            'organization_id' => (int) $organization->id,
            'branch_id' => $row->branch_id ? (int) $row->branch_id : ($botUser->branch_id ? (int) $botUser->branch_id : null),
            'phone_number_id' => $phoneNumberId,
            'access_token' => $accessToken,
            'bot_user_id' => $botUserId,
            'graph_api_version' => (string) ($row->graph_api_version ?? config('whatsapp.graph_api_version', 'v21.0')),
            'display_phone' => trim((string) ($row->display_phone ?? '')),
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function saveOrganization(Organization $organization, array $incoming): array
    {
        $moduleSettings = $organization->module_settings ?? [];
        $current = self::forOrganization($organization);
        $moduleSettings['whatsapp'] = self::mergeStored($current, $incoming);
        $organization->update(['module_settings' => $moduleSettings]);

        $row = self::configRow($organization);
        if (! $row) {
            $row = new WhatsappConfig(['organization_id' => $organization->id]);
        }

        $credentialFields = [
            'branch_id',
            'bot_user_id',
            'phone_number_id',
            'waba_id',
            'display_phone',
            'graph_api_version',
            'is_active',
        ];

        foreach ($credentialFields as $key) {
            if (array_key_exists($key, $incoming)) {
                $row->{$key} = $incoming[$key];
            }
        }

        if (array_key_exists('access_token', $incoming)) {
            $token = trim((string) $incoming['access_token']);
            if ($token !== '' && ! str_starts_with($token, '••••')) {
                $row->access_token = $token;
            }
        }

        if (! $row->graph_api_version) {
            $row->graph_api_version = (string) config('whatsapp.graph_api_version', 'v21.0');
        }

        $row->is_active = ($moduleSettings['whatsapp']['enabled'] ?? false) === true;
        $row->save();

        return self::describeForOrganization($organization->fresh());
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeStored(array $current, array $incoming): array
    {
        return self::normalize(array_merge($current, $incoming));
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $out = array_merge(self::defaults(), $settings);
        $out['enabled'] = (bool) ($out['enabled'] ?? false);
        $out['enable_whatsapp_orders'] = (bool) ($out['enable_whatsapp_orders'] ?? true);

        return $out;
    }

  /** @param  array<string, mixed>  $settings */
    public static function maskForClient(array $settings): array
    {
        return self::normalize($settings);
    }

    public static function maskCredentialsForClient(?WhatsappConfig $row): array
    {
        if (! $row) {
            return [
                'branch_id' => null,
                'bot_user_id' => null,
                'phone_number_id' => '',
                'waba_id' => '',
                'display_phone' => '',
                'graph_api_version' => (string) config('whatsapp.graph_api_version', 'v21.0'),
                'access_token_set' => false,
                'access_token_hint' => '',
            ];
        }

        $token = trim((string) ($row->access_token ?? ''));

        return [
            'branch_id' => $row->branch_id ? (int) $row->branch_id : null,
            'bot_user_id' => $row->bot_user_id ? (int) $row->bot_user_id : null,
            'phone_number_id' => (string) ($row->phone_number_id ?? ''),
            'waba_id' => (string) ($row->waba_id ?? ''),
            'display_phone' => (string) ($row->display_phone ?? ''),
            'graph_api_version' => (string) ($row->graph_api_version ?? config('whatsapp.graph_api_version', 'v21.0')),
            'access_token_set' => $token !== '',
            'access_token_hint' => $token !== '' ? '••••'.substr($token, -4) : '',
        ];
    }

    public static function webhookUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return "{$base}/api/v1/webhooks/whatsapp";
    }

    public static function platformVerifyToken(): string
    {
        $org = self::platformOrganization();
        if ($org) {
            $custom = $org->module_settings['platform_whatsapp']['webhook_verify_token'] ?? '';
            $custom = trim((string) $custom);
            if ($custom !== '') {
                return $custom;
            }
        }

        return trim((string) config('whatsapp.verify_token'));
    }

    /** @return array<string, mixed> */
    public static function describePlatform(): array
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings['platform_whatsapp'] ?? null)
            ? $org->module_settings['platform_whatsapp']
            : [];

        $token = self::platformVerifyToken();

        return [
            'scope' => 'platform',
            'webhook_url' => self::webhookUrl(),
            'webhook_verify_token_set' => $token !== '',
            'webhook_verify_token_hint' => $token !== '' ? '••••'.substr($token, -4) : '',
            'graph_api_version' => (string) ($stored['graph_api_version'] ?? config('whatsapp.graph_api_version', 'v21.0')),
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function savePlatform(array $incoming): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = is_array($moduleSettings['platform_whatsapp'] ?? null)
            ? $moduleSettings['platform_whatsapp']
            : [];

        if (array_key_exists('webhook_verify_token', $incoming)) {
            $token = trim((string) $incoming['webhook_verify_token']);
            if ($token === '' || str_starts_with($token, '••••')) {
                $incoming['webhook_verify_token'] = trim((string) ($current['webhook_verify_token'] ?? ''));
            }
        }

        $moduleSettings['platform_whatsapp'] = array_merge($current, $incoming);
        $org->update(['module_settings' => $moduleSettings]);

        return self::describePlatform();
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
    public static function clientCapabilities(CapabilityGate $gate): array
    {
        $org = $gate->organization();
        if (! $org) {
            return ['platform_enabled' => false, 'enabled' => false, 'configured' => false];
        }

        $settings = self::forOrganization($org);
        $runtime = self::resolveRuntimeForOrganization($org);

        return [
            'platform_enabled' => $gate->whatsappPlatformEnabled(),
            'enabled' => $gate->whatsappPlatformEnabled() && (bool) ($settings['enabled'] ?? false),
            'configured' => $runtime !== null,
        ];
    }

    protected static function describeBotUser(?int $botUserId): ?array
    {
        if (! $botUserId) {
            return null;
        }

        $user = User::query()->find($botUserId);
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'full_name' => $user->full_name,
            'username' => $user->username,
        ];
    }
}
