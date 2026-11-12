<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Storage\PublicMediaR2SettingsResolver;
use App\Support\OrganizationPublicStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PlatformPublicMediaStorageController extends Controller
{
    /** GET /api/v1/admin/public-media-storage-settings */
    public function show()
    {
        return response()->json(PublicMediaR2SettingsResolver::describe());
    }

    /** PUT /api/v1/admin/public-media-storage-settings */
    public function update(Request $request)
    {
        $data = $request->validate($this->rules());

        return response()->json(PublicMediaR2SettingsResolver::save($data));
    }

    /** POST /api/v1/admin/public-media-storage-settings/test-connection */
    public function testConnection(Request $request)
    {
        try {
            $overrides = $request->validate($this->rules());
            $cfg = PublicMediaR2SettingsResolver::resolveWithOverrides($overrides);
            if (! PublicMediaR2SettingsResolver::isConfigured($cfg)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Fill bucket, endpoint, and API credentials (or enable “use backup credentials” with backup R2 configured).',
                ], 422);
            }

            $this->applyTemporaryPublicDisk($cfg);
            Storage::disk('public')->files('orgs');

            return response()->json([
                'ok' => true,
                'message' => 'Connected to Cloudflare R2 media bucket.',
                'bucket' => $cfg['bucket'],
                'endpoint' => $cfg['endpoint'],
                'path_layout' => 'orgs/{COMPANY_CODE}/…',
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Cloudflare R2 media connection test failed.',
                'detail' => self::friendlyR2Error($e),
            ], 422);
        } finally {
            PublicMediaR2SettingsResolver::applyToRuntime();
        }
    }

    /** POST /api/v1/admin/public-media-storage-settings/test-upload */
    public function testUpload(Request $request)
    {
        try {
            $overrides = $request->validate($this->rules());
            $cfg = PublicMediaR2SettingsResolver::resolveWithOverrides($overrides);
            if (! PublicMediaR2SettingsResolver::isConfigured($cfg)) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Fill bucket, endpoint, and API credentials before testing upload.',
                ], 422);
            }

            $this->applyTemporaryPublicDisk($cfg);
            $fileId = OrganizationPublicStorage::path(
                OrganizationPublicStorage::platformOrganization(),
                '_healthcheck',
                'media-test-'.Str::lower(Str::random(10)).'.txt',
            );
            Storage::disk('public')->put($fileId, 'centrix-public-media-r2-ok '.now()->toIso8601String());

            return response()->json([
                'ok' => true,
                'message' => 'Uploaded a small test object to the media bucket.',
                'bucket' => $cfg['bucket'],
                'file_id' => $fileId,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'message' => 'Cloudflare R2 media upload test failed.',
                'detail' => self::friendlyR2Error($e),
            ], 422);
        } finally {
            PublicMediaR2SettingsResolver::applyToRuntime();
        }
    }

    protected static function friendlyR2Error(\Throwable $e): string
    {
        $raw = trim($e->getMessage());
        $lower = strtolower($raw);
        if (str_contains($lower, 'signaturedoesnotmatch') || str_contains($lower, 'signature we calculated')) {
            return 'R2 rejected the Access Key ID / Secret Access Key (SignatureDoesNotMatch). '
                .'In Cloudflare → R2 → Manage R2 API Tokens, create a new token with Object Read & Write '
                .'for bucket centrixerp, then paste Access Key ID + Secret Access Key into Platform settings '
                .'(untick “use backup credentials” if the backup token is wrong, or fix backup R2 credentials). '
                .'Do not use the global Cloudflare API key — only an R2 S3 API token. '
                .'Endpoint must be https://<ACCOUNT_ID>.r2.cloudflarestorage.com (no bucket path).';
        }
        if (str_contains($lower, 'invalidaccesskeyid') || str_contains($lower, 'access key')) {
            return 'R2 Access Key ID is invalid. Create a new R2 API token and paste both key and secret again.';
        }
        if (str_contains($lower, 'nosuchbucket')) {
            return 'Bucket name does not exist on this R2 account. Use exactly: centrixerp';
        }

        return $raw !== '' ? $raw : 'Unknown R2 error.';
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'use_backup_credentials' => ['sometimes', 'boolean'],
            'access_key_id' => ['nullable', 'string', 'max:255'],
            'secret_access_key' => ['nullable', 'string', 'max:255'],
            'bucket' => ['nullable', 'string', 'max:255'],
            'endpoint' => ['nullable', 'string', 'max:500'],
            'region' => ['nullable', 'string', 'max:64'],
            'public_url' => ['nullable', 'string', 'max:500'],
            'use_path_style_endpoint' => ['sometimes', 'boolean'],
        ];
    }

    /** @param  array<string, mixed>  $cfg */
    protected function applyTemporaryPublicDisk(array $cfg): void
    {
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
                'throw' => true,
            ],
        ]);
        Storage::forgetDisk('public');
    }
}
