<?php

namespace App\Services\Platform;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Compress blog images the same way Pitch Predictions admin does:
 * resize to max width, JPEG encode, extract pasted data-URI images to disk.
 *
 * Always write via Storage::disk('public') so Cloudflare R2 (when enabled as the
 * public disk) receives the object — never write only to local storage_path().
 */
class MarketingBlogImageCompressor
{
    private const MAX_WIDTH = 1200;

    private const JPEG_QUALITY = 75;

    private const DIRECTORY = 'marketing-blogs';

    public function compressUploadedFile(UploadedFile $file): string
    {
        if (! extension_loaded('gd')) {
            return $file->store(self::DIRECTORY, 'public');
        }

        $image = $this->createImageFromFile($file->getPathname(), $file->getMimeType());
        if (! $image) {
            return $file->store(self::DIRECTORY, 'public');
        }

        $image = $this->resizeIfNeeded($image, self::MAX_WIDTH);
        $relativePath = self::DIRECTORY.'/'.uniqid('blog_', true).'.jpg';
        $this->putJpegOnPublicDisk($image, $relativePath);

        return $relativePath;
    }

    public function publicUrl(string $relativePath): string
    {
        return Storage::disk('public')->url(ltrim($relativePath, '/'));
    }

    /**
     * Extract inline data:image URIs into stored files and rewrite the HTML.
     */
    public function compressImagesInHtml(string $html): string
    {
        if (stripos($html, 'data:image/') === false) {
            return $html;
        }

        @ini_set('memory_limit', '512M');
        @ini_set('pcre.backtrack_limit', (string) max(20_000_000, strlen($html) * 4));

        $needle = 'data:image/';
        $offset = 0;
        $length = strlen($html);
        $out = '';

        while ($offset < $length) {
            $pos = stripos($html, $needle, $offset);
            if ($pos === false) {
                $out .= substr($html, $offset);
                break;
            }

            $delimiter = $this->findDataUriDelimiter($html, $pos);
            if ($delimiter === null) {
                $out .= substr($html, $offset, ($pos - $offset) + strlen($needle));
                $offset = $pos + strlen($needle);
                continue;
            }

            $end = strpos($html, $delimiter, $pos);
            if ($end === false) {
                $out .= substr($html, $offset);
                break;
            }

            $dataUri = substr($html, $pos, $end - $pos);
            $normalized = preg_replace('/\s+/', '', $dataUri) ?? $dataUri;
            $storedUrl = $this->storeBase64Image($normalized);
            $out .= substr($html, $offset, $pos - $offset);
            $out .= $storedUrl ?: $dataUri;
            $offset = $end;
        }

        return $out;
    }

    protected function storeBase64Image(string $dataUri): ?string
    {
        if (! preg_match('#^data:image/(png|jpe?g|gif|webp);base64,(.+)$#i', $dataUri, $m)) {
            return null;
        }
        if (strlen($m[2]) < 200) {
            return null;
        }

        $binary = base64_decode($m[2], true);
        if ($binary === false || $binary === '') {
            return null;
        }

        if (! extension_loaded('gd')) {
            $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
            $relativePath = self::DIRECTORY.'/'.uniqid('blog_', true).'.'.$ext;
            Storage::disk('public')->put($relativePath, $binary);

            return $this->publicUrl($relativePath);
        }

        $image = @imagecreatefromstring($binary);
        if (! $image) {
            return null;
        }
        $image = $this->resizeIfNeeded($image, self::MAX_WIDTH);
        $relativePath = self::DIRECTORY.'/'.uniqid('blog_', true).'.jpg';
        $this->putJpegOnPublicDisk($image, $relativePath);

        return $this->publicUrl($relativePath);
    }

    /**
     * Encode a GD image to JPEG and put it on the configured public disk (local or R2).
     *
     * @param  \GdImage|resource  $image
     */
    protected function putJpegOnPublicDisk($image, string $relativePath): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'blog-img-');
        if ($tmp === false) {
            imagedestroy($image);
            throw new RuntimeException('Unable to create a temporary blog image file.');
        }

        try {
            if (! imagejpeg($image, $tmp, self::JPEG_QUALITY)) {
                throw new RuntimeException('Unable to encode the blog image as JPEG.');
            }
            $bytes = file_get_contents($tmp);
            if ($bytes === false || $bytes === '') {
                throw new RuntimeException('Unable to read the encoded blog image.');
            }
            Storage::disk('public')->put($relativePath, $bytes);
        } finally {
            imagedestroy($image);
            @unlink($tmp);
        }
    }

    protected function findDataUriDelimiter(string $html, int $pos): ?string
    {
        $i = $pos - 1;
        while ($i >= 0 && ctype_space($html[$i])) {
            $i--;
        }
        if ($i < 0) {
            return null;
        }
        $ch = $html[$i];
        if ($ch === '"' || $ch === "'") {
            return $ch;
        }
        if ($ch === '(') {
            return ')';
        }

        return null;
    }

    /** @param  \GdImage|resource  $image */
    protected function resizeIfNeeded($image, int $maxWidth)
    {
        $width = imagesx($image);
        $height = imagesy($image);
        if ($width <= $maxWidth) {
            return $image;
        }
        $newHeight = (int) round($height * ($maxWidth / $width));
        $resized = imagecreatetruecolor($maxWidth, $newHeight);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /** @return \GdImage|resource|null */
    protected function createImageFromFile(string $path, ?string $mime)
    {
        $mime = strtolower((string) $mime);
        return match (true) {
            str_contains($mime, 'png') => @imagecreatefrompng($path),
            str_contains($mime, 'webp') && function_exists('imagecreatefromwebp') => @imagecreatefromwebp($path),
            str_contains($mime, 'gif') => @imagecreatefromgif($path),
            default => @imagecreatefromjpeg($path) ?: @imagecreatefromstring((string) file_get_contents($path)),
        };
    }
}
