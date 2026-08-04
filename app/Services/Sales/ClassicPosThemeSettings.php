<?php

namespace App\Services\Sales;

class ClassicPosThemeSettings
{
    public const THEME_TEMPLATE_DEFAULT = 'legacy';

    public const THEME_TEMPLATES = [
        'legacy',
        'centrix',
        'ocean',
        'midnight',
        'gold',
        'safari',
        'sunset',
        'emerald',
        'slate',
        'rose',
    ];

    /** @var list<string> */
    public const COLOR_KEYS = [
        'workspace',
        'header',
        'footer',
        'button',
        'select',
    ];

    public static function normalizeThemeTemplate(mixed $value): string
    {
        $key = strtolower(trim((string) $value));
        if ($key === 'default') {
            return self::THEME_TEMPLATE_DEFAULT;
        }
        if (in_array($key, self::THEME_TEMPLATES, true)) {
            return $key;
        }

        return self::THEME_TEMPLATE_DEFAULT;
    }

    public static function normalizeHexColor(mixed $value): ?string
    {
        $raw = strtolower(trim((string) $value));
        $raw = ltrim($raw, '#');
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) === 3 && preg_match('/^[0-9a-f]{3}$/', $raw)) {
            $raw = $raw[0].$raw[0].$raw[1].$raw[1].$raw[2].$raw[2];
        }
        if (! preg_match('/^[0-9a-f]{6}$/', $raw)) {
            return null;
        }

        return '#'.$raw;
    }

    /**
     * @param  mixed  $value
     * @return array<string, string>
     */
    public static function normalizeThemeColors(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach (self::COLOR_KEYS as $key) {
            $hex = self::normalizeHexColor($value[$key] ?? null);
            if ($hex !== null) {
                $out[$key] = $hex;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>|null  $salesSettings
     * @return array{classic_pos_theme_template: string, classic_pos_theme_colors: array<string, string>}
     */
    public static function normalize(?array $salesSettings = null): array
    {
        $settings = is_array($salesSettings) ? $salesSettings : [];

        return [
            'classic_pos_theme_template' => self::normalizeThemeTemplate(
                $settings['classic_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
            ),
            'classic_pos_theme_colors' => self::normalizeThemeColors(
                $settings['classic_pos_theme_colors'] ?? [],
            ),
        ];
    }
}
