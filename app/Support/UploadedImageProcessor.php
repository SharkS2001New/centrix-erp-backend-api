<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Resize and re-encode uploaded photos before storing on the public disk. */
class UploadedImageProcessor
{
    public function __construct(
        protected int $maxWidth = 1200,
        protected int $maxHeight = 1200,
        protected int $jpegQuality = 72,
    ) {}

    public static function forLogo(): self
    {
        return new self(800, 800, 70);
    }

    public static function forDocument(): self
    {
        return new self(1400, 1400, 74);
    }

    public static function forPhoto(): self
    {
        return new self(1200, 1200, 72);
    }

    public function isProcessableImage(UploadedFile $file): bool
    {
        $mime = strtolower((string) $file->getMimeType());

        return in_array($mime, ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'], true);
    }

    /**
     * @return array{path: string, mime_type: string, size: int, file_name: string}
     */
    public function storePublicImage(UploadedFile $file, string $directory): array
    {
        $directory = trim($directory, '/');
        $disk = Storage::disk('public');

        if (! $this->isProcessableImage($file) || ! extension_loaded('gd')) {
            $path = $file->store($directory, 'public');

            return [
                'path' => $path,
                'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size' => (int) $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
            ];
        }

        $image = $this->readImage($file);
        if ($image === null) {
            $path = $file->store($directory, 'public');

            return [
                'path' => $path,
                'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size' => (int) $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
            ];
        }

        $image = $this->resizeImage($image, imagesx($image), imagesy($image));
        $filename = Str::uuid()->toString().'.jpg';
        $path = $directory.'/'.$filename;
        $disk->makeDirectory($directory);

        $tmp = tempnam(sys_get_temp_dir(), 'centrix-img-');
        if ($tmp === false) {
            imagedestroy($image);
            throw new RuntimeException('Unable to create a temporary image file.');
        }

        // Encode once at configured quality, then step down if still large.
        $quality = $this->jpegQuality;
        $bytes = null;
        $targetMax = $this->targetMaxBytes();
        while ($quality >= 42) {
            if (! imagejpeg($image, $tmp, $quality)) {
                imagedestroy($image);
                @unlink($tmp);
                throw new RuntimeException('Unable to save the optimized image.');
            }
            $encoded = file_get_contents($tmp);
            if ($encoded === false) {
                imagedestroy($image);
                @unlink($tmp);
                throw new RuntimeException('Unable to read the optimized image.');
            }
            $bytes = $encoded;
            if (strlen($bytes) <= $targetMax) {
                break;
            }
            $quality -= 8;
        }

        imagedestroy($image);
        @unlink($tmp);

        if ($bytes === false || $bytes === null) {
            throw new RuntimeException('Unable to read the optimized image.');
        }

        // Always keep the smaller payload: if re-encode grew a tiny JPEG, store the original.
        $originalSize = (int) $file->getSize();
        $originalMime = strtolower((string) $file->getMimeType());
        $preferOriginal = $originalSize > 0
            && strlen($bytes) >= $originalSize
            && in_array($originalMime, ['image/jpeg', 'image/jpg'], true)
            && $originalSize <= $targetMax;

        if ($preferOriginal) {
            $ext = 'jpg';
            $filename = Str::uuid()->toString().'.'.$ext;
            $path = $directory.'/'.$filename;
            $payload = file_get_contents($file->getRealPath()) ?: $bytes;
            $this->putPublicOrFail($disk, $path, $payload);

            return [
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'size' => $originalSize,
                'file_name' => preg_replace('/\.\w+$/', '.jpg', $file->getClientOriginalName()) ?: $filename,
            ];
        }

        $this->putPublicOrFail($disk, $path, $bytes);

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'file_name' => preg_replace('/\.\w+$/', '.jpg', $file->getClientOriginalName()) ?: $filename,
        ];
    }

    /**
     * public disk uses throw=false for R2 — verify the object landed so ERP/mobile
     * do not keep an image_path that Storage::exists() cannot read.
     */
    protected function putPublicOrFail(Filesystem $disk, string $path, string $bytes): void
    {
        $ok = $disk->put($path, $bytes);
        if ($ok === false || ! $disk->exists($path)) {
            throw new RuntimeException(
                'Could not store the image on public media storage (local or Cloudflare R2). '
                .'Check Platform → Cloudflare R2 media settings (enabled, bucket, credentials, public URL).',
            );
        }
    }

    protected function targetMaxBytes(): int
    {
        // Rough budgets aligned with frontend IMAGE_COMPRESS_PRESETS.
        if ($this->maxWidth <= 800) {
            return 220_000;
        }
        if ($this->maxWidth >= 1400) {
            return 600_000;
        }

        return 450_000;
    }

    public function storePublicImagePath(UploadedFile $file, string $directory): string
    {
        return $this->storePublicImage($file, $directory)['path'];
    }

    /** @return \GdImage|null */
    protected function readImage(UploadedFile $file): ?\GdImage
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            return null;
        }

        $mime = strtolower((string) $file->getMimeType());

        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null,
            default => null,
        };
    }

    /** @return \GdImage */
    protected function resizeImage(\GdImage $image, int $width, int $height): \GdImage
    {
        if ($width <= $this->maxWidth && $height <= $this->maxHeight) {
            return $this->flattenImage($image);
        }

        $scale = min($this->maxWidth / max($width, 1), $this->maxHeight / max($height, 1), 1);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $canvas;
    }

    /** @return \GdImage */
    protected function flattenImage(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        return $canvas;
    }
}
