<?php

namespace App\Services\Backup;

use App\Models\Organization;

class BackupR2SettingsResolver
{
    public const MODULE_KEY = 'platform_backup_r2';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => (bool) config('backup.r2.enabled', false),
            'access_key_id' => trim((string) config('backup.r2.key', '')),
            'secret_access_key' => trim((string) config('backup.r2.secret', '')),
            'bucket' => trim((string) config('backup.r2.bucket', '')),
            'endpoint' => trim((string) config('backup.r2.endpoint', '')),
            'region' => trim((string) config('backup.r2.region', 'auto')) ?: 'auto',
            'prefix' => trim((string) config('backup.r2.prefix', 'backups/database')) ?: 'backups/database',
            'public_url' => trim((string) config('backup.r2.public_url', '')),
            'use_path_style_endpoint' => (bool) config('backup.r2.use_path_style_endpoint', true),
        ];
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

    /**
     * Effective R2 settings: platform UI values override empty slots from env defaults.
     *
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        $defaults = self::defaults();
        $merged = $defaults;

        if ($stored !== []) {
            // Explicit platform save wins for enabled + non-secret fields when present.
            if (array_key_exists('enabled', $stored)) {
                $merged['enabled'] = (bool) $stored['enabled'];
            }

            foreach (['access_key_id', 'secret_access_key', 'bucket', 'endpoint', 'region', 'prefix', 'public_url'] as $key) {
                if (! array_key_exists($key, $stored)) {
                    continue;
                }
                $value = trim((string) $stored[$key]);
                if ($value !== '') {
                    $merged[$key] = $value;
                }
            }

            if (array_key_exists('use_path_style_endpoint', $stored)) {
                $merged['use_path_style_endpoint'] = (bool) $stored['use_path_style_endpoint'];
            }
        }

        return self::normalize($merged);
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $effective = self::resolve();
        $masked = self::maskForClient($effective);

        return [
            'scope' => 'platform',
            'settings' => $masked,
            'effective' => [
                'enabled' => (bool) $effective['enabled'],
                'configured' => self::isConfigured($effective),
                'upload_ready' => (bool) $effective['enabled'] && self::isConfigured($effective),
                'bucket' => $masked['bucket'],
                'endpoint' => $masked['endpoint'],
                'region' => $masked['region'],
                'prefix' => $masked['prefix'],
                'public_url' => $masked['public_url'],
                'access_key_id' => $masked['access_key_id'],
                'secret_access_key_set' => $masked['secret_access_key_set'],
                'source' => self::sourceLabel($effective),
            ],
            'hints' => [
                'endpoint' => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
                'secret' => 'Leave blank to keep the current secret access key.',
                'env_fallback' => 'Until you save here, empty fields can still fall back to BACKUP_R2_* environment variables.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function save(array $incoming): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            abort(503, 'Platform organization is not configured.');
        }

        $moduleSettings = $org->module_settings ?? [];
        $current = is_array($moduleSettings[self::MODULE_KEY] ?? null)
            ? $moduleSettings[self::MODULE_KEY]
            : [];
        $moduleSettings[self::MODULE_KEY] = self::mergeStored($current, $incoming);
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    /**
     * Push resolved credentials into runtime config so Storage::disk('r2') works.
     *
     * @return array<string, mixed>
     */
    public static function applyToRuntime(): array
    {
        return self::applyConfig(self::resolve());
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array<string, mixed>
     */
    public static function applyConfig(array $cfg): array
    {
        $cfg = self::normalize($cfg);

        config([
            'backup.r2.enabled' => (bool) $cfg['enabled'],
            'backup.r2.key' => $cfg['access_key_id'],
            'backup.r2.secret' => $cfg['secret_access_key'],
            'backup.r2.bucket' => $cfg['bucket'],
            'backup.r2.endpoint' => $cfg['endpoint'],
            'backup.r2.region' => $cfg['region'],
            'backup.r2.prefix' => $cfg['prefix'],
            'backup.r2.public_url' => $cfg['public_url'],
            'backup.r2.use_path_style_endpoint' => (bool) $cfg['use_path_style_endpoint'],
            'filesystems.disks.r2.key' => $cfg['access_key_id'],
            'filesystems.disks.r2.secret' => $cfg['secret_access_key'],
            'filesystems.disks.r2.region' => $cfg['region'],
            'filesystems.disks.r2.bucket' => $cfg['bucket'],
            'filesystems.disks.r2.url' => $cfg['public_url'] !== '' ? $cfg['public_url'] : null,
            'filesystems.disks.r2.endpoint' => $cfg['endpoint'],
            'filesystems.disks.r2.use_path_style_endpoint' => (bool) $cfg['use_path_style_endpoint'],
        ]);

        return $cfg;
    }

    /**
     * Merge optional request overrides onto the currently effective config (for connection tests).
     *
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function resolveWithOverrides(array $incoming): array
    {
        return self::mergeStored(self::resolve(), $incoming);
    }

    /** @param  array<string, mixed>  $settings */
    public static function isConfigured(array $settings): bool
    {
        foreach (['access_key_id', 'secret_access_key', 'bucket', 'endpoint'] as $key) {
            if (trim((string) ($settings[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function mergeStored(array $current, array $incoming): array
    {
        $next = self::normalize(array_merge(self::emptyStored(), $current, $incoming));

        if (array_key_exists('secret_access_key', $incoming)) {
            $secret = trim((string) $incoming['secret_access_key']);
            if ($secret === '' || str_starts_with($secret, '••••')) {
                $next['secret_access_key'] = trim((string) ($current['secret_access_key'] ?? ''));
            }
        } else {
            $next['secret_access_key'] = trim((string) ($current['secret_access_key'] ?? ''));
        }

        return $next;
    }

    /** @param  array<string, mixed>  $settings */
    public static function maskForClient(array $settings): array
    {
        $out = self::normalize($settings);
        $secret = $out['secret_access_key'] ?? '';
        unset($out['secret_access_key']);
        $out['secret_access_key_set'] = $secret !== '';
        $out['secret_access_key_hint'] = $secret !== '' ? '••••'.substr($secret, -4) : '';

        return $out;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        return [
            'enabled' => (bool) ($settings['enabled'] ?? false),
            'access_key_id' => trim((string) ($settings['access_key_id'] ?? '')),
            'secret_access_key' => trim((string) ($settings['secret_access_key'] ?? '')),
            'bucket' => trim((string) ($settings['bucket'] ?? '')),
            'endpoint' => trim((string) ($settings['endpoint'] ?? '')),
            'region' => trim((string) ($settings['region'] ?? 'auto')) ?: 'auto',
            'prefix' => trim((string) ($settings['prefix'] ?? 'backups/database')) ?: 'backups/database',
            'public_url' => trim((string) ($settings['public_url'] ?? '')),
            'use_path_style_endpoint' => (bool) ($settings['use_path_style_endpoint'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    protected static function emptyStored(): array
    {
        return [
            'enabled' => false,
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket' => '',
            'endpoint' => '',
            'region' => 'auto',
            'prefix' => 'backups/database',
            'public_url' => '',
            'use_path_style_endpoint' => true,
        ];
    }

    /** @param  array<string, mixed>  $effective */
    protected static function sourceLabel(array $effective): string
    {
        $org = self::platformOrganization();
        if (is_array($org?->module_settings[self::MODULE_KEY] ?? null)) {
            return 'platform';
        }

        if (self::isConfigured($effective) || (bool) $effective['enabled']) {
            return 'environment';
        }

        return 'unset';
    }
}
