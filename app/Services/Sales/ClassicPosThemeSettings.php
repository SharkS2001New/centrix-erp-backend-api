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

    /**
     * @param  array<string, mixed>|null  $salesSettings
     * @return array{classic_pos_theme_template: string}
     */
    public static function normalize(?array $salesSettings = null): array
    {
        $settings = is_array($salesSettings) ? $salesSettings : [];

        return [
            'classic_pos_theme_template' => self::normalizeThemeTemplate(
                $settings['classic_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
            ),
        ];
    }
}
