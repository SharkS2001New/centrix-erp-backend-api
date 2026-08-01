<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generate and attach demo product photos on the public disk (GD JPEG placeholders).
 */
class SeededProductImage
{
    /**
     * Ensure the product has a stored image. Creates one when missing.
     *
     * @param  'food'|'drinks'|'retail'|'neutral'  $tone
     */
    public static function ensureForProduct(
        Product $product,
        Organization $org,
        string $label,
        string $tone = 'neutral',
        bool $force = false,
    ): ?string {
        if (! Schema::hasColumn('products', 'image_path')) {
            return null;
        }

        if (! extension_loaded('gd')) {
            return $product->image_path;
        }

        if (
            ! $force
            && is_string($product->image_path)
            && $product->image_path !== ''
            && StoredPublicFile::exists($product->image_path)
        ) {
            return $product->image_path;
        }

        if ($product->image_path && StoredPublicFile::exists($product->image_path)) {
            Storage::disk('public')->delete($product->image_path);
        }

        $directory = OrganizationPublicStorage::path(
            $org->id,
            'products',
            (string) $product->product_code,
        );
        $filename = Str::uuid()->toString().'.jpg';
        $path = $directory.'/'.$filename;

        $bytes = self::renderJpeg($label, $tone);
        if ($bytes === null) {
            return null;
        }

        Storage::disk('public')->makeDirectory($directory);
        Storage::disk('public')->put($path, $bytes);
        $product->update(['image_path' => $path]);

        return $path;
    }

    /**
     * @param  'food'|'drinks'|'retail'|'neutral'  $tone
     */
    public static function renderJpeg(string $label, string $tone = 'neutral'): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $width = 480;
        $height = 480;
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            return null;
        }

        [$r, $g, $b] = self::toneRgb($tone, $label);
        $bg = imagecolorallocate($image, $r, $g, $b);
        $overlay = imagecolorallocate($image, max(0, $r - 28), max(0, $g - 28), max(0, $b - 28));
        $white = imagecolorallocate($image, 255, 255, 255);
        $muted = imagecolorallocatealpha($image, 255, 255, 255, 70);

        imagefilledrectangle($image, 0, 0, $width, $height, $bg);
        imagefilledellipse($image, 120, 110, 220, 180, $overlay);
        imagefilledellipse($image, 390, 390, 260, 200, $overlay);
        imagefilledrectangle($image, 0, 360, $width, $height, $muted);

        $text = self::shortLabel($label);
        $font = 5;
        $textWidth = imagefontwidth($font) * strlen($text);
        $textHeight = imagefontheight($font);
        $x = (int) max(12, ($width - $textWidth) / 2);
        $y = (int) max(12, ($height - $textHeight) / 2 - 12);
        imagestring($image, $font, $x, $y, $text, $white);

        $tmp = tempnam(sys_get_temp_dir(), 'centrix-seed-img-');
        if ($tmp === false) {
            imagedestroy($image);

            return null;
        }

        if (! imagejpeg($image, $tmp, 82)) {
            imagedestroy($image);
            @unlink($tmp);

            return null;
        }

        imagedestroy($image);
        $bytes = file_get_contents($tmp);
        @unlink($tmp);

        return $bytes === false ? null : $bytes;
    }

    /**
     * @param  'food'|'drinks'|'retail'|'neutral'  $tone
     * @return array{0: int, 1: int, 2: int}
     */
    protected static function toneRgb(string $tone, string $label): array
    {
        $hash = crc32(strtolower(trim($label)));
        $variant = abs($hash) % 3;

        return match ($tone) {
            'food' => [
                [196, 98, 45],
                [168, 72, 52],
                [140, 90, 48],
            ][$variant],
            'drinks' => [
                [36, 110, 148],
                [42, 128, 112],
                [72, 84, 150],
            ][$variant],
            'retail' => [
                [55, 118, 86],
                [78, 102, 68],
                [96, 120, 72],
            ][$variant],
            default => [
                [78, 90, 110],
                [90, 98, 118],
                [68, 86, 104],
            ][$variant],
        };
    }

    protected static function shortLabel(string $label): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($label)) ?: 'Item';
        if (strlen($clean) <= 22) {
            return $clean;
        }

        return rtrim(substr($clean, 0, 20)).'…';
    }
}
