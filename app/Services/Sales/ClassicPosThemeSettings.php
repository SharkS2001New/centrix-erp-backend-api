<?php

namespace App\Services\Sales;

class ClassicPosThemeSettings
{
    public const THEME_TEMPLATE_DEFAULT = 'centrix';

    public const THEME_TEMPLATES = [
        'centrix',
        'legacy',
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
     * @return array<string, string>
     */
    public static function themeTemplateValidationRules(string $prefix): array
    {
        return [
            $prefix => 'sometimes|string|in:'.implode(',', self::THEME_TEMPLATES),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function themeColorsValidationRules(string $prefix): array
    {
        $rules = [$prefix => 'sometimes|array'];
        foreach (self::COLOR_KEYS as $key) {
            $rules["{$prefix}.{$key}"] = 'sometimes|nullable|string|max:9';
        }

        return $rules;
    }

    /**
     * Resolve ERP modules theme with legacy fallback.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function resolveErpThemeTemplate(array $settings): string
    {
        if (array_key_exists('erp_theme_template', $settings)) {
            return self::normalizeThemeTemplate($settings['erp_theme_template']);
        }

        return self::normalizeThemeTemplate(
            $settings['classic_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function resolveErpThemeColors(array $settings): array
    {
        if (array_key_exists('erp_theme_colors', $settings)) {
            return self::normalizeThemeColors($settings['erp_theme_colors']);
        }

        return self::normalizeThemeColors($settings['classic_pos_theme_colors'] ?? []);
    }

    /**
     * Resolve External POS theme with legacy fallback.
     *
     * @param  array<string, mixed>  $settings
     */
    public static function resolveExternalPosThemeTemplate(array $settings): string
    {
        if (array_key_exists('external_pos_theme_template', $settings)) {
            return self::normalizeThemeTemplate($settings['external_pos_theme_template']);
        }

        return self::normalizeThemeTemplate(
            $settings['classic_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, string>
     */
    public static function resolveExternalPosThemeColors(array $settings): array
    {
        if (array_key_exists('external_pos_theme_colors', $settings)) {
            return self::normalizeThemeColors($settings['external_pos_theme_colors']);
        }

        return self::normalizeThemeColors($settings['classic_pos_theme_colors'] ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $salesSettings
     * @return array{
     *   classic_pos_theme_template: string,
     *   classic_pos_theme_colors: array<string, string>,
     *   erp_theme_template: string,
     *   erp_theme_colors: array<string, string>,
     *   external_pos_theme_template: string,
     *   external_pos_theme_colors: array<string, string>
     * }
     */
    public static function normalize(?array $salesSettings = null): array
    {
        $settings = is_array($salesSettings) ? $salesSettings : [];

        $legacyTemplate = self::normalizeThemeTemplate(
            $settings['classic_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
        );
        $legacyColors = self::normalizeThemeColors($settings['classic_pos_theme_colors'] ?? []);

        return [
            'classic_pos_theme_template' => $legacyTemplate,
            'classic_pos_theme_colors' => $legacyColors,
            'erp_theme_template' => self::resolveErpThemeTemplate($settings),
            'erp_theme_colors' => self::resolveErpThemeColors($settings),
            'external_pos_theme_template' => self::resolveExternalPosThemeTemplate($settings),
            'external_pos_theme_colors' => self::resolveExternalPosThemeColors($settings),
        ];
    }
}
