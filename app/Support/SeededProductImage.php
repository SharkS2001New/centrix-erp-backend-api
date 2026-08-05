<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Attach demo product photos on the public disk.
 * Prefers bundled JPEGs in resources/seed-images/hospitality, then remote URLs,
 * then GD placeholders.
 */
class SeededProductImage
{
    /**
     * Curated Unsplash photos (license: https://unsplash.com/license).
     * Keys are stable slugs used by hospitality / retail demo seeders.
     *
     * @var array<string, string>
     */
    public const PHOTO_URLS = [
        // Food
        'chips' => 'https://images.unsplash.com/photo-1576107233122-959eddb88e58?auto=format&fit=crop&w=640&h=640&q=80',
        'beef-stew' => 'https://images.unsplash.com/photo-1609607285694-e283bd2ea9a0?auto=format&fit=crop&w=640&h=640&q=80',
        'chicken-stew' => 'https://images.unsplash.com/photo-1604908176997-125f25cc6f3d?auto=format&fit=crop&w=640&h=640&q=80',
        'ugali' => 'https://images.unsplash.com/photo-1604329760661-e7ad3e2e1b2f?auto=format&fit=crop&w=640&h=640&q=80',
        'fish' => 'https://images.unsplash.com/photo-1519708227418-c8fd9a32b7a2?auto=format&fit=crop&w=640&h=640&q=80',
        'chapati' => 'https://images.unsplash.com/photo-1565557623262-b51c2513a641?auto=format&fit=crop&w=640&h=640&q=80',
        'salad' => 'https://images.unsplash.com/photo-1512621776951-a57141f2eefd?auto=format&fit=crop&w=640&h=640&q=80',
        'pilau' => 'https://images.unsplash.com/photo-1589302168068-964664d93dc0?auto=format&fit=crop&w=640&h=640&q=80',
        'githeri' => 'https://images.unsplash.com/photo-1547592166-23ac45744acd?auto=format&fit=crop&w=640&h=640&q=80',
        'nyama-choma' => 'https://images.unsplash.com/photo-1555939594-58d7cb561ad1?auto=format&fit=crop&w=640&h=640&q=80',
        // Hot drinks
        'black-coffee' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=640&h=640&q=80',
        'milk-tea' => 'https://images.unsplash.com/photo-1571934811356-5cc061b6821f?auto=format&fit=crop&w=640&h=640&q=80',
        'tea' => 'https://images.unsplash.com/photo-1571934811356-5cc061b6821f?auto=format&fit=crop&w=640&h=640&q=80',
        'coffee' => 'https://images.unsplash.com/photo-1509042239860-f550ce710b93?auto=format&fit=crop&w=640&h=640&q=80',
        // Soft drinks / water
        'soda' => 'https://images.unsplash.com/photo-1581006852262-e4307cf6283a?auto=format&fit=crop&w=640&h=640&q=80',
        'coca-cola' => 'https://images.unsplash.com/photo-1554866585-cd94860890b7?auto=format&fit=crop&w=640&h=640&q=80',
        'fanta' => 'https://images.unsplash.com/photo-1624517452488-04869289c4ca?auto=format&fit=crop&w=640&h=640&q=80',
        'sprite' => 'https://images.unsplash.com/photo-1625772299848-391b6a87d7b3?auto=format&fit=crop&w=640&h=640&q=80',
        'stoney' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?auto=format&fit=crop&w=640&h=640&q=80',
        'water' => 'https://images.unsplash.com/photo-1548839140-29a749e1cf4d?auto=format&fit=crop&w=640&h=640&q=80',
        'juice' => 'https://images.unsplash.com/photo-1622597467836-f3285f2131b8?auto=format&fit=crop&w=640&h=640&q=80',
        // Alcohol
        'beer' => 'https://images.unsplash.com/photo-1571613316887-6f8d5cbf7ef7?auto=format&fit=crop&w=640&h=640&q=80',
        'lager' => 'https://images.unsplash.com/photo-1608270586620-248524c67de9?auto=format&fit=crop&w=640&h=640&q=80',
        'guinness' => 'https://images.unsplash.com/photo-1572490122747-3968b75cc699?auto=format&fit=crop&w=640&h=640&q=80',
        'heineken' => 'https://images.unsplash.com/photo-1618885472179-5e474019f2a9?auto=format&fit=crop&w=640&h=640&q=80',
        'wine-red' => 'https://images.unsplash.com/photo-1510812431401-41d2bd2722f3?auto=format&fit=crop&w=640&h=640&q=80',
        'wine-white' => 'https://images.unsplash.com/photo-1566996667338-5de503c20591?auto=format&fit=crop&w=640&h=640&q=80',
        'champagne' => 'https://images.unsplash.com/photo-1551538827-9c037cb4f32a?auto=format&fit=crop&w=640&h=640&q=80',
        'whisky' => 'https://images.unsplash.com/photo-1527281400683-1aae777175f8?auto=format&fit=crop&w=640&h=640&q=80',
        'vodka' => 'https://images.unsplash.com/photo-1514218953589-2d7d37efd2dc?auto=format&fit=crop&w=640&h=640&q=80',
        'gin' => 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=640&h=640&q=80',
        'brandy' => 'https://images.unsplash.com/photo-1569529465841-dfecdabde8b2?auto=format&fit=crop&w=640&h=640&q=80',
        'rum' => 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=640&h=640&q=80',
        'tequila' => 'https://images.unsplash.com/photo-1516535794938-6063878f08cc?auto=format&fit=crop&w=640&h=640&q=80',
        'cocktail' => 'https://images.unsplash.com/photo-1514362545857-3bc16c4c7d1b?auto=format&fit=crop&w=640&h=640&q=80',
        'amarula' => 'https://images.unsplash.com/photo-1470337458703-46ad1756a187?auto=format&fit=crop&w=640&h=640&q=80',
        'baileys' => 'https://images.unsplash.com/photo-1470337458703-46ad1756a187?auto=format&fit=crop&w=640&h=640&q=80',
        'cider' => 'https://images.unsplash.com/photo-1608270586620-248524c67de9?auto=format&fit=crop&w=640&h=640&q=80',
        'smirnoff-ice' => 'https://images.unsplash.com/photo-1622483767028-3f66f32aef97?auto=format&fit=crop&w=640&h=640&q=80',
        'retail' => 'https://images.unsplash.com/photo-1586201375761-83865001e31c?auto=format&fit=crop&w=640&h=640&q=80',
    ];

    /**
     * Ensure the product has a stored image. Creates one when missing (or when $force).
     *
     * @param  'food'|'drinks'|'retail'|'neutral'  $tone
     */
    public static function ensureForProduct(
        Product $product,
        Organization $org,
        string $label,
        string $tone = 'neutral',
        bool $force = false,
        ?string $photoKey = null,
    ): ?string {
        if (! Schema::hasColumn('products', 'image_path')) {
            return null;
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

        $bytes = self::resolveJpegBytes($label, $tone, $photoKey);
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
    protected static function resolveJpegBytes(string $label, string $tone, ?string $photoKey): ?string
    {
        $key = self::resolvePhotoKey($photoKey, $label);
        if ($key) {
            $bundled = self::loadBundledJpeg($key);
            if ($bundled !== null) {
                return $bundled;
            }
        }

        $url = self::photoUrlFor($photoKey, $label, $tone);
        if ($url) {
            $downloaded = self::downloadJpeg($url);
            if ($downloaded !== null) {
                return $downloaded;
            }
        }

        return self::renderJpeg($label, $tone);
    }

    protected static function resolvePhotoKey(?string $photoKey, string $label): ?string
    {
        $key = strtolower(trim((string) $photoKey));
        if ($key !== '') {
            return $key;
        }

        return self::guessPhotoKey($label);
    }

    protected static function loadBundledJpeg(string $photoKey): ?string
    {
        $key = strtolower(trim($photoKey));
        if ($key === '' || str_contains($key, '..') || str_contains($key, '/')) {
            return null;
        }

        $path = resource_path('seed-images/hospitality/'.$key.'.jpg');
        if (! is_file($path)) {
            return null;
        }

        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) < 512) {
            return null;
        }

        return $bytes;
    }

    /**
     * @param  'food'|'drinks'|'retail'|'neutral'  $tone
     */
    public static function photoUrlFor(?string $photoKey, string $label = '', string $tone = 'neutral'): ?string
    {
        $key = strtolower(trim((string) $photoKey));
        if ($key !== '' && isset(self::PHOTO_URLS[$key])) {
            return self::PHOTO_URLS[$key];
        }

        $guess = self::guessPhotoKey($label);
        if ($guess && isset(self::PHOTO_URLS[$guess])) {
            return self::PHOTO_URLS[$guess];
        }

        return match ($tone) {
            'food' => self::PHOTO_URLS['nyama-choma'],
            'drinks' => self::PHOTO_URLS['beer'],
            'retail' => self::PHOTO_URLS['retail'],
            default => null,
        };
    }

    protected static function guessPhotoKey(string $label): ?string
    {
        $name = strtolower($label);
        $map = [
            'chip' => 'chips',
            'fry' => 'chips',
            'fries' => 'chips',
            'beef' => 'beef-stew',
            'stew' => 'beef-stew',
            'chicken' => 'chicken-stew',
            'ugali' => 'ugali',
            'fish' => 'fish',
            'chapati' => 'chapati',
            'salad' => 'salad',
            'pilau' => 'pilau',
            'rice' => 'pilau',
            'githeri' => 'githeri',
            'nyama' => 'nyama-choma',
            'choma' => 'nyama-choma',
            'black coffee' => 'black-coffee',
            'coffee' => 'coffee',
            'milk and tea' => 'milk-tea',
            'tea' => 'tea',
            'coca' => 'coca-cola',
            'coke' => 'coca-cola',
            'fanta' => 'fanta',
            'sprite' => 'sprite',
            'stoney' => 'stoney',
            'soda' => 'soda',
            'soft drink' => 'soda',
            'water' => 'water',
            'juice' => 'juice',
            'tusker' => 'lager',
            'white cap' => 'lager',
            'pilsner' => 'lager',
            'balozi' => 'lager',
            'senator' => 'lager',
            'guinness' => 'guinness',
            'heineken' => 'heineken',
            'smirnoff' => 'smirnoff-ice',
            'cider' => 'cider',
            'red wine' => 'wine-red',
            'white wine' => 'wine-white',
            'champagne' => 'champagne',
            'whisky' => 'whisky',
            'whiskey' => 'whisky',
            'vodka' => 'vodka',
            'gin' => 'gin',
            'brandy' => 'brandy',
            'rum' => 'rum',
            'tequila' => 'tequila',
            'cocktail' => 'cocktail',
            'amarula' => 'amarula',
            'baileys' => 'baileys',
        ];

        foreach ($map as $needle => $key) {
            if (str_contains($name, $needle)) {
                return $key;
            }
        }

        return null;
    }

    protected static function downloadJpeg(string $url): ?string
    {
        // Keep feature tests offline / fast — GD placeholders still attach images.
        if (app()->environment('testing')) {
            return null;
        }

        $cacheKey = 'seed-product-photos/'.sha1($url).'.bin';
        if (Storage::disk('local')->exists($cacheKey)) {
            $cached = Storage::disk('local')->get($cacheKey);
            if (is_string($cached) && strlen($cached) >= 512) {
                return $cached;
            }
        }

        try {
            $response = Http::timeout(12)
                ->withHeaders([
                    'Accept' => 'image/jpeg,image/*,*/*',
                    'User-Agent' => 'CentrixERP-DemoSeed/1.0',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $bytes = $response->body();
            if (! is_string($bytes) || strlen($bytes) < 512) {
                return null;
            }

            // Soft-validate JPEG/PNG magic so we don't store HTML error pages.
            $magic = substr($bytes, 0, 3);
            if ($magic !== "\xFF\xD8\xFF" && substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
                return null;
            }

            Storage::disk('local')->put($cacheKey, $bytes);

            return $bytes;
        } catch (\Throwable) {
            return null;
        }
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
