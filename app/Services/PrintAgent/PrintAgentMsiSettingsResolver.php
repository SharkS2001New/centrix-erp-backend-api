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
            'github_repo' => trim((string) env('PRINT_AGENT_MSI_GITHUB_REPO', '')),
            'github_ref' => trim((string) env('PRINT_AGENT_MSI_GITHUB_REF', 'main')) ?: 'main',
            'workflow_file' => 'build-print-agent-msi.yml',
            'github_token' => '',
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

        $repoOk = self::isValidGithubRepo((string) $settings['github_repo']);
        $tokenSet = self::githubToken() !== '';
        $publicUrlOk = self::isUsablePublicDownloadUrl($effectiveUrl);
        $r2Ok = BackupR2SettingsResolver::isConfigured($r2);

        $missing = [];
        if (! $r2Ok) {
            $missing[] = 'Cloudflare R2 (Platform → Cloudflare R2)';
        }
        if (! $tokenSet) {
            $missing[] = 'GitHub token (paste below or set PRINT_AGENT_MSI_GITHUB_TOKEN on the API)';
        }
        if (! $repoOk) {
            $missing[] = 'GitHub repo as owner/name (not a token)';
        }
        if ($effectiveUrl === '') {
            $missing[] = 'Public download URL (r2.dev or custom domain + object path)';
        } elseif (! $publicUrlOk) {
            $missing[] = 'Public download URL looks like an R2 API endpoint — use a public r2.dev / CDN URL that includes the object path';
        }

        return [
            'scope' => 'platform',
            'settings' => self::maskForClient($settings),
            'effective' => [
                'object_key' => $settings['object_key'],
                'public_url' => $effectiveUrl,
                'available' => $effectiveUrl !== '' && $publicUrlOk,
                'r2_configured' => $r2Ok,
                'r2_public_url' => trim((string) ($r2['public_url'] ?? '')),
                'r2_bucket' => trim((string) ($r2['bucket'] ?? '')),
                'github_token_set' => $tokenSet,
                'github_repo_ok' => $repoOk,
                'public_url_ok' => $publicUrlOk,
                'build_configured' => $tokenSet && $repoOk,
                'missing' => $missing,
            ],
            'hints' => [
                'path' => 'Object key in the same Cloudflare R2 bucket used for MySQL backups (e.g. print-agent/CentrixPrintAgent.msi).',
                'url' => 'Must be a public HTTPS URL that ends with the MSI path (e.g. https://pub-….r2.dev/print-agent/CentrixPrintAgent.msi). Do not paste the S3 API endpoint (*.r2.cloudflarestorage.com) alone.',
                'build' => 'Build runs on GitHub Actions (Windows + WiX), then uploads to R2. Needs a PAT with workflow scope + owner/repo of the frontend repo that contains .github/workflows/build-print-agent-msi.yml.',
                'repo' => 'Example: your-org/centrix-erp-frontend-web — never paste a ghp_ token here.',
                'token' => 'Personal access token with “workflow” (and usually “contents: read”) on that repo. Stored for the platform org, or set PRINT_AGENT_MSI_GITHUB_TOKEN on the API.',
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

        if (array_key_exists('github_repo', $incoming)) {
            $repo = trim((string) $incoming['github_repo']);
            if ($repo !== '' && ! self::isValidGithubRepo($repo)) {
                if (self::looksLikeGithubToken($repo)) {
                    abort(422, 'GitHub repo must be owner/name (e.g. acme/centrix-erp-frontend-web). You pasted a token into the repo field — put the token in the GitHub token field instead.');
                }
                abort(422, 'GitHub repo must look like owner/repository.');
            }
        }

        if (array_key_exists('public_url', $incoming)) {
            $url = trim((string) $incoming['public_url']);
            if ($url !== '' && ! self::isUsablePublicDownloadUrl($url)) {
                abort(422, 'Public download URL must be a full public link to the MSI (include /print-agent/….msi). The R2 S3 API host (*.r2.cloudflarestorage.com) alone is not a download URL — use your r2.dev public bucket URL or CDN.');
            }
        }

        $merged = self::normalize(array_merge($current, $incoming));

        // Blank / masked token keeps the existing stored token (env still applies as fallback).
        if (array_key_exists('github_token', $incoming)) {
            $token = trim((string) $incoming['github_token']);
            if ($token === '' || str_starts_with($token, '••••')) {
                $merged['github_token'] = (string) ($current['github_token'] ?? '');
            } else {
                $merged['github_token'] = $token;
            }
        }

        // If public URL left empty, derive from backup R2 public base + object key.
        if (trim((string) ($incoming['public_url'] ?? '')) === '' && trim((string) $merged['object_key']) !== '') {
            $r2 = BackupR2SettingsResolver::resolve();
            $derived = self::derivePublicUrl($merged['object_key'], (string) ($r2['public_url'] ?? ''));
            if ($derived !== '' && self::isUsablePublicDownloadUrl($derived)) {
                $merged['public_url'] = $derived;
            }
        }

        $org->putModuleSettingsSection(self::MODULE_KEY, $merged);
        self::platformOrganization(refresh: true);

        return self::describe();
    }

    public static function githubToken(): string
    {
        $settings = self::forPlatform();
        $fromSettings = trim((string) ($settings['github_token'] ?? ''));
        if ($fromSettings !== '') {
            return $fromSettings;
        }

        return trim((string) (
            env('PRINT_AGENT_MSI_GITHUB_TOKEN', '')
            ?: env('GITHUB_TOKEN', '')
        ));
    }

    public static function isValidGithubRepo(string $repo): bool
    {
        $repo = trim($repo);
        if ($repo === '' || self::looksLikeGithubToken($repo)) {
            return false;
        }

        return (bool) preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo);
    }

    public static function looksLikeGithubToken(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('#^(ghp_|github_pat_|gho_|ghu_|ghs_|ghr_)#i', $value);
    }

    /**
     * Reject bare R2 S3 API endpoints (no object key) — tills cannot download from those.
     */
    public static function isUsablePublicDownloadUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');

        // API-style endpoint with no object path.
        if (str_contains($host, 'r2.cloudflarestorage.com') && $path === '') {
            return false;
        }

        // Prefer URLs that actually point at an MSI (or at least have a path).
        if ($path === '') {
            return false;
        }

        return true;
    }

    public static function derivePublicUrl(string $objectKey, string $publicBase): string
    {
        $base = rtrim(trim($publicBase), '/');
        $key = ltrim(trim($objectKey), '/');
        if ($base === '' || $key === '') {
            return '';
        }

        // If the base is already an S3 API host, derived links still will not be publicly fetchable
        // without signed URLs — still form the path so admins see what key is expected.
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

        $repo = trim((string) ($settings['github_repo'] ?? ''));
        // Do not persist a PAT mistakenly typed into the repo field.
        if (self::looksLikeGithubToken($repo)) {
            $repo = '';
        }

        return [
            'object_key' => $objectKey,
            'public_url' => trim((string) ($settings['public_url'] ?? '')),
            'github_repo' => $repo,
            'github_ref' => trim((string) ($settings['github_ref'] ?? 'main')) ?: 'main',
            'workflow_file' => trim((string) ($settings['workflow_file'] ?? 'build-print-agent-msi.yml')) ?: 'build-print-agent-msi.yml',
            'github_token' => trim((string) ($settings['github_token'] ?? '')),
            'last_build_status' => trim((string) ($settings['last_build_status'] ?? '')),
            'last_build_at' => $settings['last_build_at'] ?? null,
            'last_build_message' => trim((string) ($settings['last_build_message'] ?? '')),
            'last_upload_at' => $settings['last_upload_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function maskForClient(array $settings): array
    {
        $token = trim((string) ($settings['github_token'] ?? ''));
        unset($settings['github_token']);
        $settings['github_token_set'] = $token !== '' || trim((string) env('PRINT_AGENT_MSI_GITHUB_TOKEN', '')) !== '';
        $settings['github_token_hint'] = $token !== ''
            ? '••••'.substr($token, -4)
            : (trim((string) env('PRINT_AGENT_MSI_GITHUB_TOKEN', '')) !== ''
                ? 'set via API env'
                : '');

        return $settings;
    }
}
