<?php

namespace App\Services\PrintAgent;

use App\Models\Organization;
use App\Services\Backup\BackupR2SettingsResolver;

class PrintAgentMsiSettingsResolver
{
    public const MODULE_KEY = 'platform_print_agent_msi';

    public const DEFAULT_OBJECT_KEY = 'print-agent/CentrixPrintAgent.msi';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'object_key' => self::DEFAULT_OBJECT_KEY,
            'public_url' => '',
            'github_repo' => trim((string) config('services.print_agent_msi.github_repo', env('PRINT_AGENT_MSI_GITHUB_REPO', ''))),
            'github_ref' => trim((string) config('services.print_agent_msi.github_ref', env('PRINT_AGENT_MSI_GITHUB_REF', 'main'))) ?: 'main',
            'workflow_file' => trim((string) config('services.print_agent_msi.workflow_file', 'build-print-agent-msi.yml')) ?: 'build-print-agent-msi.yml',
            'last_build_status' => '',
            'last_build_at' => null,
            'last_build_message' => '',
            'last_upload_at' => null,
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
        $stored = is_array($org?->module_settings[self::MODULE_KEY] ?? null)
            ? $org->module_settings[self::MODULE_KEY]
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function describe(): array
    {
        $settings = self::forPlatform();
        $r2 = BackupR2SettingsResolver::resolve();
        $derivedUrl = self::derivePublicUrl($settings['object_key'], (string) ($r2['public_url'] ?? ''));
        $effectiveUrl = trim((string) $settings['public_url']) !== ''
            ? trim((string) $settings['public_url'])
            : $derivedUrl;

        return [
            'scope' => 'platform',
            'settings' => $settings,
            'effective' => [
                'object_key' => $settings['object_key'],
                'public_url' => $effectiveUrl,
                'available' => $effectiveUrl !== '',
                'r2_configured' => BackupR2SettingsResolver::isConfigured($r2),
                'r2_public_url' => trim((string) ($r2['public_url'] ?? '')),
                'r2_bucket' => trim((string) ($r2['bucket'] ?? '')),
                'build_configured' => self::githubToken() !== '' && trim((string) $settings['github_repo']) !== '',
            ],
            'hints' => [
                'path' => 'Object key in the same Cloudflare R2 bucket used for MySQL backups (e.g. print-agent/CentrixPrintAgent.msi).',
                'url' => 'Public download URL. Leave blank to derive from R2 public URL + object key.',
                'build' => 'Build runs on GitHub Actions (Windows + WiX), then uploads to R2. Configure PRINT_AGENT_MSI_GITHUB_TOKEN and github repo.',
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

        $current = self::forPlatform();
        $merged = self::normalize(array_merge($current, $incoming));

        // If public URL left empty, derive from backup R2 public base + object key.
        if (trim((string) ($incoming['public_url'] ?? '')) === '' && trim((string) $merged['object_key']) !== '') {
            $r2 = BackupR2SettingsResolver::resolve();
            $derived = self::derivePublicUrl($merged['object_key'], (string) ($r2['public_url'] ?? ''));
            if ($derived !== '') {
                $merged['public_url'] = $derived;
            }
        }

        $org->putModuleSettingsSection(self::MODULE_KEY, $merged);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    public static function githubToken(): string
    {
        return trim((string) (
            config('services.print_agent_msi.github_token')
            ?: env('PRINT_AGENT_MSI_GITHUB_TOKEN', '')
            ?: env('GITHUB_TOKEN', '')
        ));
    }

    public static function derivePublicUrl(string $objectKey, string $publicBase): string
    {
        $base = rtrim(trim($publicBase), '/');
        $key = ltrim(trim($objectKey), '/');
        if ($base === '' || $key === '') {
            return '';
        }

        return $base.'/'.$key;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function normalize(array $settings): array
    {
        $objectKey = trim((string) ($settings['object_key'] ?? ''));
        if ($objectKey === '') {
            $objectKey = self::DEFAULT_OBJECT_KEY;
        }
        $objectKey = str_replace('\\', '/', $objectKey);
        $objectKey = preg_replace('#/+#', '/', $objectKey) ?? $objectKey;
        $objectKey = ltrim($objectKey, '/');

        return [
            'object_key' => $objectKey,
            'public_url' => trim((string) ($settings['public_url'] ?? '')),
            'github_repo' => trim((string) ($settings['github_repo'] ?? '')),
            'github_ref' => trim((string) ($settings['github_ref'] ?? 'main')) ?: 'main',
            'workflow_file' => trim((string) ($settings['workflow_file'] ?? 'build-print-agent-msi.yml')) ?: 'build-print-agent-msi.yml',
            'last_build_status' => trim((string) ($settings['last_build_status'] ?? '')),
            'last_build_at' => $settings['last_build_at'] ?? null,
            'last_build_message' => trim((string) ($settings['last_build_message'] ?? '')),
            'last_upload_at' => $settings['last_upload_at'] ?? null,
        ];
    }
}
