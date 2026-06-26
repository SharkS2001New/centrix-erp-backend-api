<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Support\AppTimezone;

class GeneralSettingsResolver
{
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
        $out['show_organization_on_documents'] = (bool) ($out['show_organization_on_documents'] ?? true);
        $out['document_header_display'] = in_array($out['document_header_display'] ?? '', ['auto', 'logo', 'name', 'logo_and_name'], true)
            ? $out['document_header_display']
            : 'auto';

        return $out;
    }
}
