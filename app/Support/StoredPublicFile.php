<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Stream files from the public storage disk (local or S3/MinIO). */
class StoredPublicFile
{
    public static function disk(): Filesystem
    {
        return Storage::disk('public');
    }

    public static function exists(?string $path): bool
    {
        return is_string($path) && $path !== '' && self::disk()->exists($path);
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function response(
        ?string $path,
        ?string $defaultMime = 'application/octet-stream',
        array $headers = [],
    ): StreamedResponse {
        if (! self::exists($path)) {
            abort(404);
        }

        $mime = self::disk()->mimeType($path) ?: $defaultMime;

        return response()->stream(function () use ($path) {
            $stream = self::disk()->readStream($path);
            if (! is_resource($stream)) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, array_merge([
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=3600',
        ], $headers));
    }
}
