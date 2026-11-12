<?php

namespace App\Services\Storage;

use App\Models\Organization;
use App\Services\Backup\BackupR2SettingsResolver;
use Illuminate\Support\Facades\Storage;

/**
 * Platform-admin Cloudflare R2 settings for product photos and other public media.
 * Object keys stay org-scoped: orgs/{COMPANY_CODE}/…
 *
 * Optionally reuse the same API token as database-backup R2 while keeping a separate bucket.
 */
class PublicMediaR2SettingsResolver
{
    public const MODULE_KEY = 'platform_public_media_r2';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => strtolower((string) env('PUBLIC_STORAGE_DRIVER', 'local')) === 'r2',
            'use_backup_credentials' => true,
            'access_key_id' => trim((string) env('PUBLIC_R2_ACCESS_KEY_ID', env('BACKUP_R2_ACCESS_KEY_ID', ''))),
            'secret_access_key' => trim((string) env('PUBLIC_R2_SECRET_ACCESS_KEY', env('BACKUP_R2_SECRET_ACCESS_KEY', ''))),
            'bucket' => trim((string) env('PUBLIC_R2_BUCKET', '')),
            'endpoint' => trim((string) env('PUBLIC_R2_ENDPOINT', env('BACKUP_R2_ENDPOINT', ''))),
            'region' => trim((string) env('PUBLIC_R2_REGION', env('BACKUP_R2_REGION', 'auto'))) ?: 'auto',
            'public_url' => trim((string) env('PUBLIC_R2_PUBLIC_URL', '')),
            'use_path_style_endpoint' => filter_var(
                env('PUBLIC_R2_USE_PATH_STYLE_ENDPOINT', env('BACKUP_R2_USE_PATH_STYLE_ENDPOINT', true)),
                FILTER_VALIDATE_BOOL,
            ),
        ];
    }

    public static function platformOrganization(bool $refresh = false): ?Organization
    {
        return BackupR2SettingsResolver::platformOrganization($refresh);
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolve(): array
    {
        $org = self::platformOrganization();
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        $merged = self::defaults();
        if ($stored !== []) {
            if (array_key_exists('enabled', $stored)) {
                $merged['enabled'] = (bool) $stored['enabled'];
            }
            if (array_key_exists('use_backup_credentials', $stored)) {
                $merged['use_backup_credentials'] = (bool) $stored['use_backup_credentials'];
            }
            foreach (['access_key_id', 'secret_access_key', 'bucket', 'endpoint', 'region', 'public_url'] as $key) {
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

        $merged = self::normalize($merged);

        if ($merged['use_backup_credentials']) {
            $backup = BackupR2SettingsResolver::resolve();
            foreach (['access_key_id', 'secret_access_key', 'endpoint', 'region'] as $key) {
                $value = trim((string) ($backup[$key] ?? ''));
                if ($value !== '') {
                    $merged[$key] = $value;
                }
            }
            if (array_key_exists('use_path_style_endpoint', $backup)) {
                $merged['use_path_style_endpoint'] = (bool) $backup['use_path_style_endpoint'];
            }
            // Bucket stays media-specific; only fall back to backup bucket if media bucket empty.
            if ($merged['bucket'] === '' && trim((string) ($backup['bucket'] ?? '')) !== '') {
                $merged['bucket'] = trim((string) $backup['bucket']);
            }
        }

        return self::normalize($merged);
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $effective = self::resolve();
        $masked = self::maskForClient($effective);
        $backup = BackupR2SettingsResolver::resolve();

        $configured = self::isConfigured($effective);
        $ready = (bool) $effective['enabled'] && $configured;
        $publicUrl = trim((string) ($effective['public_url'] ?? ''));

        return [
            'scope' => 'platform',
            'settings' => $masked,
            'effective' => [
                'enabled' => (bool) $effective['enabled'],
                'configured' => $configured,
                'ready' => $ready,
                'public_url_set' => $publicUrl !== '',
                // Direct <img src> for ERP/mobile/POS tiles needs a public/custom domain;
                // without it apps still read via authenticated /image/file.
                'browser_direct_ready' => $ready && $publicUrl !== '',
                'use_backup_credentials' => (bool) $effective['use_backup_credentials'],
                'bucket' => $masked['bucket'],
                'endpoint' => $masked['endpoint'],
                'region' => $masked['region'],
                'public_url' => $masked['public_url'],
                'access_key_id' => $masked['access_key_id'],
                'secret_access_key_set' => $masked['secret_access_key_set'],
                'path_layout' => 'orgs/{COMPANY_CODE}/…',
                'source' => self::sourceLabel($effective),
                'backup_credentials_available' => BackupR2SettingsResolver::isConfigured($backup),
            ],
            'hints' => [
                'endpoint' => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
                'secret' => 'Leave blank to keep the current secret access key.',
                'use_backup' => 'Reuse the same R2 API token as Database backups; you can still set a different media bucket.',
                'path_layout' => 'Files are stored as orgs/{COMPANY_CODE}/products/… so tenants stay separated.',
                'env_fallback' => 'Until you save here, empty fields can fall back to PUBLIC_R2_* / BACKUP_R2_* environment variables.',
                'public_url' => 'Set an r2.dev or custom domain URL and enable Public Access on the media bucket so ERP, mobile, and POS can load image_url directly. Without it, clients use authenticated /image/file.',
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

        self::applyToRuntime();

        return self::describe();
    }

    /**
     * Push resolved credentials onto the runtime "public" disk (product photos, etc.).
     *
     * @return array<string, mixed>
     */
    public static function applyToRuntime(): array
    {
        $cfg = self::resolve();
        if (! ((bool) $cfg['enabled'] && self::isConfigured($cfg))) {
            return $cfg;
        }

        // PHPUnit seeds write product photos to the public disk — keep local there unless forced.
        if (app()->runningUnitTests() && ! filter_var(env('PUBLIC_MEDIA_R2_APPLY_IN_TESTS', false), FILTER_VALIDATE_BOOL)) {
            return $cfg;
        }

        // R2 rejects object ACLs — never set visibility/ACL on this disk.
        config([
            'filesystems.disks.public' => [
                'driver' => 's3',
                'key' => $cfg['access_key_id'],
                'secret' => $cfg['secret_access_key'],
                'region' => $cfg['region'] ?: 'auto',
                'bucket' => $cfg['bucket'],
                'url' => $cfg['public_url'] !== '' ? $cfg['public_url'] : null,
                'endpoint' => $cfg['endpoint'],
                'use_path_style_endpoint' => (bool) $cfg['use_path_style_endpoint'],
                'throw' => false,
            ],
        ]);

        Storage::forgetDisk('public');

        return $cfg;
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function resolveWithOverrides(array $incoming): array
    {
        $base = self::resolve();
        $merged = self::mergeStored($base, $incoming);
        // mergeStored does not re-apply backup credential inheritance for overrides used in tests.
        if (! empty($merged['use_backup_credentials'])) {
            $backup = BackupR2SettingsResolver::resolveWithOverrides(
                array_intersect_key($incoming, array_flip([
                    'access_key_id',
                    'secret_access_key',
                    'endpoint',
                    'region',
                    'use_path_style_endpoint',
                    'bucket',
                ])),
            );
            foreach (['access_key_id', 'secret_access_key', 'endpoint', 'region'] as $key) {
                $value = trim((string) ($backup[$key] ?? ''));
                if ($value !== '') {
                    $merged[$key] = $value;
                }
            }
            if (array_key_exists('use_path_style_endpoint', $backup)) {
                $merged['use_path_style_endpoint'] = (bool) $backup['use_path_style_endpoint'];
            }
        }

        return self::normalize($merged);
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
            'use_backup_credentials' => (bool) ($settings['use_backup_credentials'] ?? true),
            'access_key_id' => trim((string) ($settings['access_key_id'] ?? '')),
            'secret_access_key' => trim((string) ($settings['secret_access_key'] ?? '')),
            'bucket' => trim((string) ($settings['bucket'] ?? '')),
            'endpoint' => trim((string) ($settings['endpoint'] ?? '')),
            'region' => trim((string) ($settings['region'] ?? 'auto')) ?: 'auto',
            'public_url' => trim((string) ($settings['public_url'] ?? '')),
            'use_path_style_endpoint' => (bool) ($settings['use_path_style_endpoint'] ?? true),
        ];
    }

    /** @return array<string, mixed> */
    protected static function emptyStored(): array
    {
        return [
            'enabled' => false,
            'use_backup_credentials' => true,
            'access_key_id' => '',
            'secret_access_key' => '',
            'bucket' => '',
            'endpoint' => '',
            'region' => 'auto',
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
