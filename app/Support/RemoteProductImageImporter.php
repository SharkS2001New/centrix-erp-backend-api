<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/** Download a public image URL and store it like an uploaded product photo. */
class RemoteProductImageImporter
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @return array{path: string, mime_type: string, size: int, file_name: string}
     */
    public function import(string $url, string $directory): array
    {
        $url = trim($url);
        $this->assertPublicHttpUrl($url);

        try {
            $response = Http::timeout(20)
                ->withHeaders([
                    'Accept' => 'image/jpeg,image/png,image/webp,image/*,*/*',
                    'User-Agent' => 'CentrixERP/1.0',
                ])
                ->withOptions(['allow_redirects' => false])
                ->get($url);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages([
                'url' => 'Could not download that image. Check the link and try again.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'url' => 'Could not download that image (HTTP '.$response->status().'). Use a direct image link.',
            ]);
        }

        $bytes = $response->body();
        if (! is_string($bytes) || strlen($bytes) < 32) {
            throw ValidationException::withMessages([
                'url' => 'That URL did not return an image.',
            ]);
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'url' => 'Image is larger than 5 MB.',
            ]);
        }

        $mime = $this->detectImageMime($bytes, (string) $response->header('Content-Type'));
        if ($mime === null) {
            throw ValidationException::withMessages([
                'url' => 'That URL is not a JPEG, PNG, or WebP image.',
            ]);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'centrix-remote-img-');
        if ($tmp === false) {
            throw ValidationException::withMessages([
                'url' => 'Could not save the downloaded image.',
            ]);
        }

        file_put_contents($tmp, $bytes);
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };
        $file = new UploadedFile($tmp, 'remote.'.$ext, $mime, null, true);

        try {
            return UploadedImageProcessor::forPhoto()->storePublicImage($file, $directory);
        } finally {
            @unlink($tmp);
        }
    }

    public function assertPublicHttpUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! is_array($parts) || ! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw ValidationException::withMessages([
                'url' => 'Enter a public http or https image URL.',
            ]);
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || $host === 'metadata.google.internal'
        ) {
            throw ValidationException::withMessages([
                'url' => 'Enter a public image URL, not a local or private address.',
            ]);
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
            if (! filter_var($host, FILTER_VALIDATE_IP, $flags)) {
                throw ValidationException::withMessages([
                    'url' => 'Enter a public image URL, not a local or private address.',
                ]);
            }
        }
    }

    protected function detectImageMime(string $bytes, string $contentType): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if (in_array($contentType, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true)) {
            return $contentType === 'image/jpg' ? 'image/jpeg' : $contentType;
        }

        return null;
    }
}
