<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/** Resize and re-encode uploaded photos before storing on the public disk. */
class UploadedImageProcessor
{
    public function __construct(
        protected int $maxWidth = 1600,
        protected int $maxHeight = 1600,
        protected int $jpegQuality = 82,
    ) {}

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

        if (! imagejpeg($image, $tmp, $this->jpegQuality)) {
            imagedestroy($image);
            @unlink($tmp);
            throw new RuntimeException('Unable to save the optimized image.');
        }

        imagedestroy($image);

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false) {
            throw new RuntimeException('Unable to read the optimized image.');
        }

        $disk->put($path, $bytes);

        return [
            'path' => $path,
            'mime_type' => 'image/jpeg',
            'size' => strlen($bytes),
            'file_name' => preg_replace('/\.\w+$/', '.jpg', $file->getClientOriginalName()) ?: $filename,
        ];
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
