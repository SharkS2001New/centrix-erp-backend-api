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
        'reports.daily_sales' => 'sales_orders',
        'reports.sales_by_product' => 'sales_orders',
        'reports.sales_by_supplier' => 'suppliers',
        'reports.sales_by_user' => 'sales_orders',
        'reports.sales_by_customer' => 'customers',
        'reports.sales_by_channel' => 'sales_orders',
        'reports.sales_pipeline' => 'sales_orders',
        'reports.category_sales' => 'products',
        'reports.credit_outstanding' => 'customers',
        'reports.stock_on_hand' => 'inventory',
        'reports.low_stock' => 'inventory',
        'reports.stock_movement' => 'inventory',
        'reports.stock_chain' => 'inventory',
        'reports.stock_valuation' => 'inventory',
        'reports.stock_reservations' => 'inventory',
        'reports.stock_transfers' => 'stock_movements',
        'reports.branch_stock_transfers' => 'stock_movements',
        'reports.returns' => 'after_sales',
        'reports.price_list' => 'products',
        'reports.open_lpo' => 'suppliers',
        'reports.purchases_by_supplier' => 'suppliers',
        'reports.stock_receipts' => 'stock_movements',
        'reports.supplier_returns' => 'suppliers',
        'reports.damages' => 'stock_movements',
        'reports.eod_cashier' => 'pos',
        'reports.eod_report' => 'pos',
        'reports.till_sessions' => 'pos',
        'reports.discount_summary' => 'pos',
        'reports.payment_collection' => 'pos',
        'reports.vat_collected' => 'pos',
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

        return self::applyTillFloat($out, false);
    }

    /**
     * Till sessions, end of day, and till management live in Till operations.
     * A till float keeps that group on.
     *
     * @param  array<string, bool>  $sections
     * @return array<string, bool>
     */
    public static function applyTillFloat(array $sections, bool $tillFloat): array
    {
        if ($tillFloat) {
            $sections['pos'] = true;
        }

        return $sections;
    }

    public static function salesUsesTillFloat(array $sales): bool
    {
        return ! empty($sales['require_pos_till_float']) || ! empty($sales['require_backoffice_till_float']);
    }

    /** @return array<string, bool> */
    public static function forOrganization(?Organization $organization): array
    {
        if (! $organization) {
            return self::DEFAULTS;
        }

        $sales = app(CapabilityGate::class)->forOrganization($organization)->moduleSettings('sales');
        $stored = is_array($sales['backoffice_sections'] ?? null) ? $sales['backoffice_sections'] : null;

        return self::applyTillFloat(self::normalize($stored), self::salesUsesTillFloat($sales));
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
