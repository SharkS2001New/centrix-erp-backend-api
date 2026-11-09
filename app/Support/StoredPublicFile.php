<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

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
    ): Response {
        if (! self::exists($path)) {
            abort(404);
        }

        $disk = self::disk();
        $mime = $disk->mimeType($path) ?: $defaultMime;
        $size = null;
        $lastModified = null;

        try {
            $size = $disk->size($path);
        } catch (\Throwable) {
            $size = null;
        }
        try {
            $lastModified = $disk->lastModified($path);
        } catch (\Throwable) {
            $lastModified = null;
        }

        $etagSource = ($path ?? '').'|'.($size ?? 0).'|'.($lastModified ?? 0);
        $etag = '"'.sha1($etagSource).'"';

        $request = request();
        $ifNoneMatch = trim((string) $request->header('If-None-Match', ''));
        if ($ifNoneMatch !== '' && trim($ifNoneMatch, '"') === trim($etag, '"')) {
            return response('', 304, array_merge([
                'ETag' => $etag,
                'Cache-Control' => self::defaultCacheControl($mime, $headers),
            ], $headers));
        }

        $merged = array_merge([
            'Content-Type' => $mime,
            'ETag' => $etag,
            'Accept-Ranges' => 'bytes',
            // Private (auth) but cacheable so browsers / EntityPhotoDisplay can reuse bytes.
            'Cache-Control' => self::defaultCacheControl($mime, $headers),
        ], $headers);

        if ($size !== null) {
            $merged['Content-Length'] = (string) $size;
        }
        if ($lastModified) {
            $merged['Last-Modified'] = gmdate('D, d M Y H:i:s', $lastModified).' GMT';
        }

        return response()->stream(function () use ($path) {
            $stream = self::disk()->readStream($path);
            if (! is_resource($stream)) {
                return;
            }

            fpassthru($stream);
            fclose($stream);
        }, 200, $merged);
    }

    /**
     * @param  array<string, string>  $headers
     */
    protected static function defaultCacheControl(string $mime, array $headers): string
    {
        if (isset($headers['Cache-Control'])) {
            return $headers['Cache-Control'];
        }

        if (str_starts_with(strtolower($mime), 'image/')) {
            // Product / shop / employee photos — long private cache; ETag validates freshness.
            return 'private, max-age=86400, stale-while-revalidate=604800';
        }

        return 'private, max-age=3600';
    }
}
