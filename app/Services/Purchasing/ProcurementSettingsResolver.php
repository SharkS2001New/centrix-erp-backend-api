<?php

namespace App\Services\Purchasing;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class ProcurementSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.procurement', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $stored = is_array($organization->module_settings['procurement'] ?? null)
            ? $organization->module_settings['procurement']
            : [];

        $settings = self::normalize(array_merge(self::defaults(), $stored));

        return self::alignReceiveLocationWithSales($organization, $settings);
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
        $settings = self::normalize(array_merge(
            self::defaults(),
            $gate->moduleSettings('procurement'),
        ));

        $organization = $gate->organization();
        if ($organization) {
            return self::alignReceiveLocationWithSales($organization, $settings);
        }

        return $settings;
    }

    /**
     * Keep receive location aligned with where the till sells from.
     * Shop-only / small_shop orgs must receive into shop or POS shows zero stock.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public static function alignReceiveLocationWithSales(Organization $organization, array $settings): array
    {
        if (($organization->deployment_profile ?? '') === 'small_shop') {
            $settings['default_receive_location'] = 'shop';

            return $settings;
        }

        $sales = is_array($organization->module_settings['sales'] ?? null)
            ? $organization->module_settings['sales']
            : [];

        // Match config/erp.php sales defaults when keys are unset.
        $allowShop = array_key_exists('allow_sell_from_shop', $sales)
            ? (bool) $sales['allow_sell_from_shop']
            : (bool) (config('erp.module_settings_defaults.sales.allow_sell_from_shop') ?? true);
        $allowStore = array_key_exists('allow_sell_from_store', $sales)
            ? (bool) $sales['allow_sell_from_store']
            : (bool) (config('erp.module_settings_defaults.sales.allow_sell_from_store') ?? false);

        if ($allowShop && ! $allowStore) {
            $settings['default_receive_location'] = 'shop';
        }

        return $settings;
    }

    /** True when the org must receive into shop (POS cannot sell store-only stock). */
    public static function mustReceiveIntoShop(Organization $organization): bool
    {
        $settings = self::forOrganization($organization);

        return ($settings['default_receive_location'] ?? 'store') === 'shop'
            && (($organization->deployment_profile ?? '') === 'small_shop'
                || self::isShopOnlySales($organization));
    }

    public static function isShopOnlySales(Organization $organization): bool
    {
        $sales = is_array($organization->module_settings['sales'] ?? null)
            ? $organization->module_settings['sales']
            : [];

        $allowShop = array_key_exists('allow_sell_from_shop', $sales)
            ? (bool) $sales['allow_sell_from_shop']
            : (bool) (config('erp.module_settings_defaults.sales.allow_sell_from_shop') ?? true);
        $allowStore = array_key_exists('allow_sell_from_store', $sales)
            ? (bool) $sales['allow_sell_from_store']
            : (bool) (config('erp.module_settings_defaults.sales.allow_sell_from_store') ?? false);

        return $allowShop && ! $allowStore;
    }

    /** @param  array<string, mixed>  $settings */
    public static function normalize(array $settings): array
    {
        $defaults = self::defaults();
        $out = array_merge($defaults, $settings);

        $out['default_payment_terms_days'] = max(0, min(365, (int) ($out['default_payment_terms_days'] ?? 30)));
        $out['require_lpo_approval'] = (bool) ($out['require_lpo_approval'] ?? true);
        $out['default_receive_location'] = in_array($out['default_receive_location'] ?? '', ['shop', 'store'], true)
            ? $out['default_receive_location']
            : 'store';
        $out['auto_email_supplier_on_lpo'] = (bool) ($out['auto_email_supplier_on_lpo'] ?? false);
        $out['lpo_print_delivery_notes'] = trim((string) ($out['lpo_print_delivery_notes'] ?? ''));
        $out['lpo_print_kebs_warning'] = trim((string) ($out['lpo_print_kebs_warning'] ?? ''));
        $out['lpo_print_vat_note'] = trim((string) ($out['lpo_print_vat_note'] ?? ''));
        $out['lpo_print_footer_lines'] = trim((string) ($out['lpo_print_footer_lines'] ?? ''));
        $out['lpo_print_validity_days'] = max(1, (int) ($out['lpo_print_validity_days'] ?? 7));
        $out['lpo_print_checked_by'] = trim((string) ($out['lpo_print_checked_by'] ?? ''));
        $out['lpo_print_authorised_by'] = trim((string) ($out['lpo_print_authorised_by'] ?? ''));
        $tpl = strtolower(trim((string) ($out['lpo_document_template'] ?? 'default')));
        $out['lpo_document_template'] = $tpl !== '' ? substr($tpl, 0, 40) : 'default';

        return $out;
    }
}
