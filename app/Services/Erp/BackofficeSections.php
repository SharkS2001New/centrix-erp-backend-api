<?php

namespace App\Services\Erp;

use App\Models\Organization;

/**
 * Platform switches for Backoffice sidebar sections.
 * Missing keys stay on so existing organizations keep the full sidebar.
 */
class BackofficeSections
{
    /** @var list<string> */
    public const SECTION_KEYS = [
        'dashboard',
        'products',
        'pos',
        'pricing_tax',
        'sales_orders',
        'shop_debtors',
        'field_sales',
        'legacy_system',
        'after_sales',
        'promotions',
        'customers',
        'expenses',
        'investors',
        'inventory',
        'stock_movements',
        'suppliers',
        'reports',
    ];

    /** @var array<string, bool> */
    public const DEFAULTS = [
        'dashboard' => true,
        'products' => true,
        'pos' => true,
        'pricing_tax' => true,
        'sales_orders' => true,
        'shop_debtors' => true,
        'field_sales' => true,
        'legacy_system' => true,
        'after_sales' => true,
        'promotions' => true,
        'customers' => true,
        'expenses' => true,
        'investors' => true,
        'inventory' => true,
        'stock_movements' => true,
        'suppliers' => true,
        'reports' => true,
    ];

    /**
     * Registry feature (module.feature) → Backoffice sidebar section.
     * Unmapped features stay available.
     *
     * @var array<string, string>
     */
    public const FEATURE_SECTIONS = [
        'dashboard.overview' => 'dashboard',
        'dashboard.sales' => 'dashboard',
        'dashboard.inventory' => 'dashboard',
        'catalogue.products' => 'products',
        'catalogue.categories' => 'products',
        'catalogue.uoms' => 'products',
        'catalogue.retail_packages' => 'products',
        'pricing_tax.vat_rates' => 'pricing_tax',
        'pricing_tax.price_history' => 'pricing_tax',
        'pricing_tax.kra_invoices' => 'pricing_tax',
        'pos.till_management' => 'pos',
        'pos.end_of_day' => 'pos',
        'pos.payments_breakdown' => 'pos',
        'customers.customers' => 'customers',
        'customers.statements' => 'customers',
        'shop_debtors.debtors' => 'shop_debtors',
        'shop_debtors.payments' => 'shop_debtors',
        'sales.orders' => 'sales_orders',
        'sales.collect_payment' => 'sales_orders',
        'sales.discounts' => 'sales_orders',
        'sales.carts' => 'sales_orders',
        'sales.reservations' => 'sales_orders',
        'sales.returns' => 'after_sales',
        'sales.vouchers' => 'promotions',
        'sales.loyalty_cards' => 'promotions',
        'sales.loading_sheets' => 'field_sales',
        'sales.field_attendance' => 'field_sales',
        'sales.legacy_orders' => 'legacy_system',
        'accounting.expenses' => 'expenses',
        'investors.investors' => 'investors',
        'investors.reports' => 'investors',
        'inventory.stock' => 'inventory',
        'inventory.stock_take' => 'inventory',
        'inventory.movements' => 'inventory',
        'inventory.transfers' => 'stock_movements',
        'inventory.receipts' => 'stock_movements',
        'inventory.adjustments' => 'stock_movements',
        'inventory.damages' => 'stock_movements',
        'purchasing.suppliers' => 'suppliers',
        'purchasing.lpo' => 'suppliers',
        'purchasing.supplier_payments' => 'suppliers',
        'purchasing.supplier_returns' => 'suppliers',
        'reports.hub' => 'reports',
        'reports.builder' => 'reports',
        'reports.daily_sales' => 'reports',
        'reports.sales_by_product' => 'reports',
        'reports.sales_by_supplier' => 'reports',
        'reports.sales_by_user' => 'reports',
        'reports.sales_by_customer' => 'reports',
        'reports.sales_by_channel' => 'reports',
        'reports.sales_pipeline' => 'reports',
        'reports.category_sales' => 'reports',
        'reports.credit_outstanding' => 'reports',
        'reports.stock_on_hand' => 'reports',
        'reports.low_stock' => 'reports',
        'reports.stock_movement' => 'reports',
        'reports.stock_chain' => 'reports',
        'reports.stock_valuation' => 'reports',
        'reports.stock_reservations' => 'reports',
        'reports.stock_transfers' => 'reports',
        'reports.branch_stock_transfers' => 'reports',
        'reports.returns' => 'reports',
        'reports.price_list' => 'reports',
        'reports.open_lpo' => 'reports',
        'reports.purchases_by_supplier' => 'reports',
        'reports.stock_receipts' => 'reports',
        'reports.supplier_returns' => 'reports',
        'reports.damages' => 'reports',
        'reports.eod_cashier' => 'reports',
        'reports.eod_report' => 'reports',
        'reports.till_sessions' => 'reports',
        'reports.discount_summary' => 'reports',
        'reports.payment_collection' => 'reports',
        'reports.vat_collected' => 'reports',
        'reports.legacy_archive' => 'reports',
    ];

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<string, bool>
     */
    public static function normalize(?array $raw): array
    {
        $out = self::DEFAULTS;
        if (! is_array($raw)) {
            return $out;
        }

        foreach (self::SECTION_KEYS as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = filter_var($raw[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $out;
    }

    /** @return array<string, bool> */
    public static function forOrganization(?Organization $organization): array
    {
        if (! $organization) {
            return self::DEFAULTS;
        }

        $sales = app(CapabilityGate::class)->forOrganization($organization)->moduleSettings('sales');
        $stored = is_array($sales['backoffice_sections'] ?? null) ? $sales['backoffice_sections'] : null;

        return self::normalize($stored);
    }

    public static function enabled(?Organization $organization, string $section): bool
    {
        $sections = self::forOrganization($organization);

        return (bool) ($sections[$section] ?? true);
    }

    public static function sectionForFeature(string $module, string $feature): ?string
    {
        if ($module === 'sales' && str_starts_with($feature, 'order_queue_')) {
            return 'sales_orders';
        }

        if ($module === 'shop_debtors') {
            return 'shop_debtors';
        }

        return self::FEATURE_SECTIONS[$module.'.'.$feature] ?? null;
    }

    public static function sectionForPermissionCode(string $code): ?string
    {
        if (preg_match('/^sales\.(order_queue_[a-z0-9_]+)\./', $code) === 1) {
            return 'sales_orders';
        }

        if (preg_match('/^([a-z0-9_]+)\.([a-z0-9_]+)\./', $code, $matches) !== 1) {
            return null;
        }

        return self::sectionForFeature($matches[1], $matches[2]);
    }

    /**
     * @param  list<array<string, mixed>>  $modules
     * @param  array<string, bool>  $sections
     * @return list<array<string, mixed>>
     */
    public static function filterApplicationModules(array $modules, array $sections): array
    {
        $out = [];
        foreach ($modules as $module) {
            if (! is_array($module)) {
                continue;
            }
            $moduleKey = (string) ($module['module'] ?? '');
            $features = [];
            foreach ($module['features'] ?? [] as $feature) {
                if (! is_array($feature)) {
                    continue;
                }
                $section = self::sectionForFeature($moduleKey, (string) ($feature['key'] ?? ''));
                if ($section !== null && ($sections[$section] ?? true) === false) {
                    continue;
                }
                $features[] = $feature;
            }
            if ($features === []) {
                continue;
            }
            $module['features'] = $features;
            $out[] = $module;
        }

        return $out;
    }
}
