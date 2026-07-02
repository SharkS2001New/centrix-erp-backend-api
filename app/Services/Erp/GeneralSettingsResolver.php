<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Support\AppTimezone;

class GeneralSettingsResolver
{
    public const PRINT_FONT_FAMILIES = [
        'times', 'georgia', 'palatino', 'garamond', 'book_antiqua', 'cambria', 'constantia',
        'arial', 'helvetica', 'verdana', 'tahoma', 'trebuchet', 'calibri', 'segoe_ui', 'aptos',
        'lucida_sans', 'franklin_gothic', 'century_gothic', 'courier', 'lucida_console', 'system',
    ];

    public const PRINT_FONT_SCALES = ['compact', 'standard', 'large', 'extra_large', 'custom'];

    public const PRINT_FONT_WEIGHTS = ['normal', 'medium', 'semibold', 'bold', 'extra_bold'];

    /** @var array<string, array{family: string, scale: string, size_px: int, weight: string, header_scale: string, header_weight: string, footer_scale: string, footer_weight: string}> */
    public const PRINT_FONT_VARIANT_DEFAULTS = [
        'receipt' => [
            'family' => 'arial', 'scale' => 'standard', 'size_px' => 11, 'weight' => 'semibold',
            'header_scale' => 'large', 'header_weight' => 'semibold',
            'footer_scale' => 'standard', 'footer_weight' => 'semibold',
        ],
        'invoice' => [
            'family' => 'times', 'scale' => 'standard', 'size_px' => 14, 'weight' => 'semibold',
            'header_scale' => 'large', 'header_weight' => 'semibold',
            'footer_scale' => 'standard', 'footer_weight' => 'semibold',
        ],
        'lpo' => [
            'family' => 'times', 'scale' => 'standard', 'size_px' => 14, 'weight' => 'semibold',
            'header_scale' => 'large', 'header_weight' => 'semibold',
            'footer_scale' => 'standard', 'footer_weight' => 'semibold',
        ],
        'loading_sheet' => [
            'family' => 'arial', 'scale' => 'standard', 'size_px' => 16, 'weight' => 'semibold',
            'header_scale' => 'large', 'header_weight' => 'semibold',
            'footer_scale' => 'standard', 'footer_weight' => 'semibold',
        ],
        'report' => [
            'family' => 'times', 'scale' => 'standard', 'size_px' => 14, 'weight' => 'semibold',
            'header_scale' => 'large', 'header_weight' => 'semibold',
            'footer_scale' => 'standard', 'footer_weight' => 'semibold',
        ],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.general', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['general'] ?? null)
            ? $organization->module_settings['general']
            : [];

        return self::normalize(array_merge(self::defaults(), $stored));
    }

    /** @return array<string, mixed> */
    public static function forOrganizationId(?int $organizationId): array
    {
        if (! $organizationId) {
            return self::normalize(self::defaults());
        }

        $organization = Organization::find($organizationId);

        return $organization
            ? self::forOrganization($organization)
            : self::normalize(self::defaults());
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        return self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('general'),
        ));
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $out['currency'] = strtoupper(trim((string) ($out['currency'] ?? 'KES'))) ?: 'KES';
        $out['timezone'] = trim((string) ($out['timezone'] ?? AppTimezone::DEFAULT)) ?: AppTimezone::DEFAULT;
        $out['date_format'] = in_array($out['date_format'] ?? '', ['DD/MM/YYYY', 'MM/DD/YYYY', 'YYYY-MM-DD'], true)
            ? $out['date_format']
            : 'DD/MM/YYYY';
        $out['language'] = in_array($out['language'] ?? '', ['en', 'sw'], true)
            ? $out['language']
            : 'en';
        $out['decimal_places'] = max(0, min(4, (int) ($out['decimal_places'] ?? 2)));
        $out['fiscal_year_start_month'] = max(1, min(12, (int) ($out['fiscal_year_start_month'] ?? 1)));
        $out['week_starts_on'] = in_array($out['week_starts_on'] ?? '', ['monday', 'sunday'], true)
            ? $out['week_starts_on']
            : 'monday';
        $out['phone_country_code'] = trim((string) ($out['phone_country_code'] ?? '+254')) ?: '+254';
        $out['default_country_code'] = strtoupper(trim((string) ($out['default_country_code'] ?? 'KE'))) ?: 'KE';
        $out['number_thousands_separator'] = in_array($out['number_thousands_separator'] ?? '', ['comma', 'space', 'none'], true)
            ? $out['number_thousands_separator']
            : 'comma';
        $out['document_footer_text'] = trim((string) ($out['document_footer_text'] ?? ''));
        $out['print_footer_receipt'] = trim((string) ($out['print_footer_receipt'] ?? ''));
        $out['print_footer_a4_invoice'] = trim((string) ($out['print_footer_a4_invoice'] ?? ''));
        $out['print_footer_lpo'] = trim((string) ($out['print_footer_lpo'] ?? ''));
        $out['print_footer_loading_sheet'] = trim((string) ($out['print_footer_loading_sheet'] ?? ''));
        $out['show_organization_on_documents'] = (bool) ($out['show_organization_on_documents'] ?? true);
        $out['document_header_display'] = in_array($out['document_header_display'] ?? '', ['auto', 'logo', 'name', 'logo_and_name'], true)
            ? $out['document_header_display']
            : 'auto';

        return self::normalizePrintFonts($out, $settings);
    }

    /**
     * @param  array<string, mixed>  $out
     * @param  array<string, mixed>  $source  Raw settings before defaults merge — used for per-variant fallback.
     */
    public static function normalizePrintFonts(array $out, array $source = []): array
    {
        $legacyFamily = in_array($out['print_font_family'] ?? '', self::PRINT_FONT_FAMILIES, true)
            ? $out['print_font_family']
            : 'times';
        $legacyScale = in_array($out['print_font_scale'] ?? '', self::PRINT_FONT_SCALES, true)
            ? $out['print_font_scale']
            : 'standard';
        $legacySizePx = max(8, min(24, (int) ($out['print_font_size_px'] ?? 14)));
        $legacyWeight = in_array($out['print_font_weight'] ?? '', self::PRINT_FONT_WEIGHTS, true)
            ? $out['print_font_weight']
            : 'semibold';

        $out['print_font_family'] = $legacyFamily;
        $out['print_font_scale'] = $legacyScale;
        $out['print_font_size_px'] = $legacySizePx;
        $out['print_font_weight'] = $legacyWeight;

        foreach (self::PRINT_FONT_VARIANT_DEFAULTS as $variant => $defaults) {
            $familyKey = "print_font_{$variant}_family";
            $scaleKey = "print_font_{$variant}_scale";
            $sizeKey = "print_font_{$variant}_size_px";
            $weightKey = "print_font_{$variant}_weight";
            $headerScaleKey = "print_font_{$variant}_header_scale";
            $headerSizeKey = "print_font_{$variant}_header_size_px";
            $headerWeightKey = "print_font_{$variant}_header_weight";
            $footerScaleKey = "print_font_{$variant}_footer_scale";
            $footerSizeKey = "print_font_{$variant}_footer_size_px";
            $footerWeightKey = "print_font_{$variant}_footer_weight";

            $hasSpecific = array_key_exists($familyKey, $source)
                || array_key_exists($scaleKey, $source)
                || array_key_exists($sizeKey, $source)
                || array_key_exists($weightKey, $source)
                || array_key_exists($headerScaleKey, $source)
                || array_key_exists($headerSizeKey, $source)
                || array_key_exists($headerWeightKey, $source)
                || array_key_exists($footerScaleKey, $source)
                || array_key_exists($footerSizeKey, $source)
                || array_key_exists($footerWeightKey, $source);

            $out[$familyKey] = in_array($out[$familyKey] ?? '', self::PRINT_FONT_FAMILIES, true)
                ? $out[$familyKey]
                : ($hasSpecific ? $defaults['family'] : $legacyFamily);
            $out[$scaleKey] = in_array($out[$scaleKey] ?? '', self::PRINT_FONT_SCALES, true)
                ? $out[$scaleKey]
                : ($hasSpecific ? $defaults['scale'] : $legacyScale);
            $out[$sizeKey] = max(
                8,
                min(24, (int) ($out[$sizeKey] ?? ($hasSpecific ? $defaults['size_px'] : $legacySizePx))),
            );
            $out[$weightKey] = in_array($out[$weightKey] ?? '', self::PRINT_FONT_WEIGHTS, true)
                ? $out[$weightKey]
                : ($hasSpecific ? $defaults['weight'] : $legacyWeight);

            $out[$headerScaleKey] = in_array($out[$headerScaleKey] ?? '', self::PRINT_FONT_SCALES, true)
                ? $out[$headerScaleKey]
                : $defaults['header_scale'];
            $out[$headerSizeKey] = max(
                8,
                min(24, (int) ($out[$headerSizeKey] ?? $defaults['size_px'])),
            );
            $out[$headerWeightKey] = in_array($out[$headerWeightKey] ?? '', self::PRINT_FONT_WEIGHTS, true)
                ? $out[$headerWeightKey]
                : $defaults['header_weight'];
            $out[$footerScaleKey] = in_array($out[$footerScaleKey] ?? '', self::PRINT_FONT_SCALES, true)
                ? $out[$footerScaleKey]
                : $defaults['footer_scale'];
            $out[$footerSizeKey] = max(
                8,
                min(24, (int) ($out[$footerSizeKey] ?? max(8, $defaults['size_px'] - 2))),
            );
            $out[$footerWeightKey] = in_array($out[$footerWeightKey] ?? '', self::PRINT_FONT_WEIGHTS, true)
                ? $out[$footerWeightKey]
                : $defaults['footer_weight'];
        }

        return $out;
    }

    /** @return array<string, string> */
    public static function printFontValidationRules(): array
    {
        $familyRule = 'sometimes|in:'.implode(',', self::PRINT_FONT_FAMILIES);
        $scaleRule = 'sometimes|in:'.implode(',', self::PRINT_FONT_SCALES);
        $sizeRule = 'sometimes|integer|min:8|max:24';
        $weightRule = 'sometimes|in:'.implode(',', self::PRINT_FONT_WEIGHTS);

        $rules = [
            'print_font_family' => $familyRule,
            'print_font_scale' => $scaleRule,
            'print_font_size_px' => $sizeRule,
            'print_font_weight' => $weightRule,
        ];

        foreach (array_keys(self::PRINT_FONT_VARIANT_DEFAULTS) as $variant) {
            $rules["print_font_{$variant}_family"] = $familyRule;
            $rules["print_font_{$variant}_scale"] = $scaleRule;
            $rules["print_font_{$variant}_size_px"] = $sizeRule;
            $rules["print_font_{$variant}_weight"] = $weightRule;
            $rules["print_font_{$variant}_header_scale"] = $scaleRule;
            $rules["print_font_{$variant}_header_size_px"] = $sizeRule;
            $rules["print_font_{$variant}_header_weight"] = $weightRule;
            $rules["print_font_{$variant}_footer_scale"] = $scaleRule;
            $rules["print_font_{$variant}_footer_size_px"] = $sizeRule;
            $rules["print_font_{$variant}_footer_weight"] = $weightRule;
        }

        return $rules;
    }
}
