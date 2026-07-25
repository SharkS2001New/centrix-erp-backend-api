<?php

namespace App\Services\PrintAgent;

use App\Services\Backup\BackupR2SettingsResolver;
use App\Services\Backup\CloudflareR2BackupUploader;
use App\Services\Backup\DatabaseBackupException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PrintAgentMsiService
{
    public function __construct(
        protected CloudflareR2BackupUploader $r2,
    ) {}

    /**
     * Trigger GitHub Actions workflow_dispatch for build-print-agent-msi.yml.
     *
     * @return array<string, mixed>
     */
    public function queueBuild(): array
    {
        $settings = PrintAgentMsiSettingsResolver::forPlatform();
        $token = PrintAgentMsiSettingsResolver::githubToken();
        $repo = trim((string) $settings['github_repo']);
        $ref = trim((string) $settings['github_ref']) ?: 'main';
        $workflow = trim((string) $settings['workflow_file']) ?: 'build-print-agent-msi.yml';

        if ($token === '' || $repo === '') {
            throw new \RuntimeException(
                'GitHub build is not configured. Set PRINT_AGENT_MSI_GITHUB_TOKEN on the API and the github repo (owner/name) in Print Agent MSI settings.',
            );
        }

        if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            throw new \RuntimeException('GitHub repo must look like owner/repository.');
        }

        $r2 = BackupR2SettingsResolver::resolve();
        if (! BackupR2SettingsResolver::isConfigured($r2)) {
            throw new \RuntimeException(
                'Configure Cloudflare R2 under Platform → Cloudflare R2 before building the MSI (same bucket as MySQL backups).',
            );
        }

        $url = "https://api.github.com/repos/{$repo}/actions/workflows/".rawurlencode($workflow).'/dispatches';
        $response = Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'centrix-erp-backend-api',
            ])
            ->post($url, ['ref' => $ref]);

        if ($response->status() !== 204 && ! $response->successful()) {
            Log::warning('Print Agent MSI GitHub dispatch failed', [
                'repo' => $repo,
                'workflow' => $workflow,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            $message = $response->json('message') ?: $response->body() ?: 'GitHub workflow dispatch failed.';
            throw new \RuntimeException('Could not start MSI build: '.$message);
        }

        $publicUrl = trim((string) $settings['public_url']);
        if ($publicUrl === '') {
            $publicUrl = PrintAgentMsiSettingsResolver::derivePublicUrl(
                (string) $settings['object_key'],
                (string) ($r2['public_url'] ?? ''),
            );
        }

        $patch = [
            'last_build_status' => 'queued',
            'last_build_at' => now()->toIso8601String(),
            'last_build_message' => "Workflow {$workflow} dispatched on {$repo}@{$ref}. Upload lands at {$settings['object_key']} when the job finishes.",
        ];
        if ($publicUrl !== '') {
            $patch['public_url'] = $publicUrl;
        }

        $described = PrintAgentMsiSettingsResolver::save($patch);

        return [
            'ok' => true,
            'message' => 'MSI build queued on GitHub Actions. When it finishes, the file is on R2 at the configured path.',
            'repo' => $repo,
            'ref' => $ref,
            'workflow' => $workflow,
            'object_key' => $settings['object_key'],
            'public_url' => $described['effective']['public_url'] ?? $publicUrl,
            'settings' => $described,
        ];
    }

    /**
     * Upload an MSI file to the configured R2 object key.
     *
     * @return array<string, mixed>
     */
    public function uploadMsi(UploadedFile $file): array
    {
        $settings = PrintAgentMsiSettingsResolver::forPlatform();
        $objectKey = (string) $settings['object_key'];
        $name = strtolower((string) $file->getClientOriginalName());
        if (! str_ends_with($name, '.msi')) {
            throw new \InvalidArgumentException('Upload a .msi file.');
        }

        try {
            $uploaded = $this->r2->uploadToObjectKey(
                $file->getRealPath(),
                $objectKey,
                'application/octet-stream',
            );
        } catch (DatabaseBackupException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $publicUrl = $uploaded['web_view_link']
            ?: PrintAgentMsiSettingsResolver::derivePublicUrl(
                $objectKey,
                (string) (BackupR2SettingsResolver::resolve()['public_url'] ?? ''),
            );

        $described = PrintAgentMsiSettingsResolver::save([
            'public_url' => $publicUrl ?: (string) $settings['public_url'],
            'last_upload_at' => now()->toIso8601String(),
            'last_build_status' => 'uploaded',
            'last_build_at' => now()->toIso8601String(),
            'last_build_message' => 'MSI uploaded directly to R2 from Platform settings.',
        ]);

        return [
            'ok' => true,
            'message' => 'MSI uploaded to Cloudflare R2.',
            'object_key' => $uploaded['file_id'],
            'bucket' => $uploaded['bucket'],
            'public_url' => $described['effective']['public_url'] ?? $publicUrl,
            'settings' => $described,
        ];
    }
}
