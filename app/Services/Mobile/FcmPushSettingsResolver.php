<?php

namespace App\Services\Mobile;

use App\Models\Organization;
use Illuminate\Support\Facades\File;

class FcmPushSettingsResolver
{
    public const CREDENTIALS_FILENAME = 'platform-fcm-service-account.json';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => (bool) config('manager_push.enabled', false),
            'fcm_project_id' => trim((string) config('manager_push.fcm_project_id', '')),
            'ignore_local_tokens' => (bool) config('manager_push.ignore_local_tokens', true),
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

    /** @return array<string, mixed> */
    public static function forPlatform(): array
    {
        $org = self::platformOrganization();
        if (! $org) {
            return [];
        }

        $custom = $org->module_settings['platform_fcm_push'] ?? [];

        return is_array($custom) ? $custom : [];
    }

    /** @return array<string, mixed> */
    public static function resolve(): array
    {
        $stored = self::forPlatform();

        $enabled = array_key_exists('enabled', $stored)
            ? (bool) $stored['enabled']
            : (bool) config('manager_push.enabled', false);

        $projectId = trim((string) ($stored['fcm_project_id'] ?? ''));
        if ($projectId === '') {
            $projectId = trim((string) config('manager_push.fcm_project_id', ''));
        }

        $ignoreLocal = array_key_exists('ignore_local_tokens', $stored)
            ? (bool) $stored['ignore_local_tokens']
            : (bool) config('manager_push.ignore_local_tokens', true);

        $credentialsPath = self::resolveCredentialsPath();

        return [
            'enabled' => $enabled,
            'fcm_project_id' => $projectId,
            'fcm_credentials_path' => $credentialsPath,
            'ignore_local_tokens' => $ignoreLocal,
            'credentials_file_exists' => self::credentialsFileExists($credentialsPath),
            'uses_platform_storage' => self::platformCredentialsFileExists(),
            'uses_env_credentials' => self::envCredentialsFileExists(),
        ];
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $stored = self::forPlatform();
        $resolved = self::resolve();
        $credentialsMeta = self::describeCredentials($resolved['fcm_credentials_path']);

        return [
            'scope' => 'platform',
            'settings' => [
                'enabled' => array_key_exists('enabled', $stored)
                    ? (bool) $stored['enabled']
                    : null,
                'fcm_project_id' => trim((string) ($stored['fcm_project_id'] ?? '')),
                'ignore_local_tokens' => array_key_exists('ignore_local_tokens', $stored)
                    ? (bool) $stored['ignore_local_tokens']
                    : null,
            ],
            'effective' => [
                'enabled' => (bool) $resolved['enabled'],
                'fcm_project_id' => (string) $resolved['fcm_project_id'],
                'ignore_local_tokens' => (bool) $resolved['ignore_local_tokens'],
            ],
            'credentials_set' => (bool) ($credentialsMeta['credentials_set'] ?? false),
            'credentials_client_email' => $credentialsMeta['client_email'] ?? '',
            'credentials_source' => $credentialsMeta['source'] ?? null,
            'env_fallback_active' => self::envFallbackActive($stored),
            'apps' => [
                'manager' => 'Centrix Manager (pending approvals)',
                'mobile_sales' => 'Centrix Mobile (discount approval outcomes)',
            ],
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
        $current = self::forPlatform();

        if (array_key_exists('enabled', $incoming)) {
            $current['enabled'] = (bool) $incoming['enabled'];
        }

        if (array_key_exists('fcm_project_id', $incoming)) {
            $current['fcm_project_id'] = trim((string) $incoming['fcm_project_id']);
        }

        if (array_key_exists('ignore_local_tokens', $incoming)) {
            $current['ignore_local_tokens'] = (bool) $incoming['ignore_local_tokens'];
        }

        if (($incoming['clear_credentials'] ?? false) === true) {
            self::deletePlatformCredentialsFile();
        } elseif (array_key_exists('credentials_json', $incoming)) {
            $json = trim((string) $incoming['credentials_json']);
            if ($json !== '' && ! str_starts_with($json, '••••')) {
                self::storePlatformCredentials($json);
            }
        }

        $moduleSettings['platform_fcm_push'] = $current;
        $org->update(['module_settings' => $moduleSettings]);
        self::platformOrganization(true);

        return self::describe();
    }

    public static function platformCredentialsPath(): string
    {
        return storage_path('app/private/firebase/'.self::CREDENTIALS_FILENAME);
    }

    protected static function resolveCredentialsPath(): string
    {
        $platformPath = self::platformCredentialsPath();
        if (self::credentialsFileExists($platformPath)) {
            return $platformPath;
        }

        $envPath = config('manager_push.fcm_credentials_path');
        if (is_string($envPath) && $envPath !== '') {
            return $envPath;
        }

        return $platformPath;
    }

    protected static function platformCredentialsFileExists(): bool
    {
        return self::credentialsFileExists(self::platformCredentialsPath());
    }

    protected static function envCredentialsFileExists(): bool
    {
        $envPath = config('manager_push.fcm_credentials_path');

        return is_string($envPath) && $envPath !== '' && self::credentialsFileExists($envPath);
    }

    protected static function credentialsFileExists(?string $path): bool
    {
        return is_string($path) && $path !== '' && is_file($path);
    }

    /** @param  array<string, mixed>  $stored */
    protected static function envFallbackActive(array $stored): bool
    {
        $usesStoredEnabled = array_key_exists('enabled', $stored);
        $usesStoredProject = trim((string) ($stored['fcm_project_id'] ?? '')) !== '';
        $usesStoredIgnore = array_key_exists('ignore_local_tokens', $stored);

        return ! $usesStoredEnabled
            || ! $usesStoredProject
            || ! $usesStoredIgnore
            || (! self::platformCredentialsFileExists() && self::envCredentialsFileExists());
    }

    protected static function storePlatformCredentials(string $json): void
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            abort(422, 'Service account JSON is invalid.');
        }

        $clientEmail = trim((string) ($decoded['client_email'] ?? ''));
        $privateKey = trim((string) ($decoded['private_key'] ?? ''));
        if ($clientEmail === '' || $privateKey === '') {
            abort(422, 'Service account JSON must include client_email and private_key.');
        }

        $directory = dirname(self::platformCredentialsPath());
        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0750, true);
        }

        $written = file_put_contents(self::platformCredentialsPath(), json_encode($decoded, JSON_UNESCAPED_SLASHES));
        if ($written === false) {
            abort(500, 'Could not save Firebase service account file.');
        }

        @chmod(self::platformCredentialsPath(), 0600);
    }

    protected static function deletePlatformCredentialsFile(): void
    {
        $path = self::platformCredentialsPath();
        if (is_file($path)) {
            unlink($path);
        }
    }

    /** @return array{credentials_set: bool, client_email: string, source: string|null} */
    protected static function describeCredentials(string $path): array
    {
        if (! self::credentialsFileExists($path)) {
            return [
                'credentials_set' => false,
                'client_email' => '',
                'source' => null,
            ];
        }

        $json = json_decode((string) file_get_contents($path), true);
        $clientEmail = is_array($json) ? trim((string) ($json['client_email'] ?? '')) : '';

        return [
            'credentials_set' => $clientEmail !== '',
            'client_email' => $clientEmail,
            'source' => $path === self::platformCredentialsPath() ? 'platform' : 'env',
        ];
    }
}
