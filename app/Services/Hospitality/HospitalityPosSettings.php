<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class HospitalityPosSettings
{
    public const GRID_COLUMNS_DEFAULT = 4;

    public const GRID_COLUMNS_ALLOWED = [4, 5];

    public const CATALOG_LIMIT_DEFAULT = 30;

    public const THEME_TEMPLATE_DEFAULT = 'centrix';

    public const THEME_TEMPLATES = [
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

    /**
     * @param  array<string, mixed>|null  $hospitalitySettings
     */
    public static function normalizeGridColumns(mixed $value, ?array $hospitalitySettings = null): int
    {
        $raw = $value;
        if ($raw === null && is_array($hospitalitySettings)) {
            $raw = $hospitalitySettings['hotel_pos_grid_columns'] ?? null;
        }
        $n = (int) $raw;
        if (! in_array($n, self::GRID_COLUMNS_ALLOWED, true)) {
            return self::GRID_COLUMNS_DEFAULT;
        }

        return $n;
    }

    public static function normalizeCatalogLimit(mixed $value): int
    {
        $n = (int) $value;
        if ($n < 8) {
            return self::CATALOG_LIMIT_DEFAULT;
        }

        return min(60, max(8, $n));
    }

    public static function normalizeCollectPayment(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

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
     * @return array{
     *   hotel_pos_grid_columns: int,
     *   hotel_pos_collect_payment: bool,
     *   hotel_pos_catalog_limit: int,
     *   stock_deduct_on_settle: bool,
     *   stock_location: string,
     *   block_settle_if_insufficient: bool,
     *   require_recipe_for_stocked_items: bool,
     *   pos_email_reports: array<string, mixed>
     * }
     */
    public static function forOrganization(?Organization $organization): array
    {
        if (! $organization) {
            return self::defaults();
        }

        $gate = app(CapabilityGate::class)->forOrganization($organization);
        $settings = $gate->moduleSettings('hospitality');

        return [
            'hotel_pos_grid_columns' => self::normalizeGridColumns($settings['hotel_pos_grid_columns'] ?? null),
            'hotel_pos_collect_payment' => self::normalizeCollectPayment($settings['hotel_pos_collect_payment'] ?? true),
            'hotel_pos_catalog_limit' => self::normalizeCatalogLimit(
                $settings['hotel_pos_catalog_limit'] ?? self::CATALOG_LIMIT_DEFAULT,
            ),
            'stock_deduct_on_settle' => self::normalizeBool($settings['stock_deduct_on_settle'] ?? false, false),
            'stock_location' => self::normalizeStockLocation($settings['stock_location'] ?? 'shop'),
            'block_settle_if_insufficient' => self::normalizeBool(
                $settings['block_settle_if_insufficient'] ?? true,
                true,
            ),
            'require_recipe_for_stocked_items' => self::normalizeBool(
                $settings['require_recipe_for_stocked_items'] ?? false,
                false,
            ),
            'hotel_pos_theme_template' => self::normalizeThemeTemplate(
                $settings['hotel_pos_theme_template'] ?? self::THEME_TEMPLATE_DEFAULT,
            ),
            'check_receipt_copies' => max(1, min(3, (int) ($settings['check_receipt_copies'] ?? 1))),
            'show_outlet_on_check_receipt' => self::normalizeBool(
                $settings['show_outlet_on_check_receipt'] ?? true,
                true,
            ),
            'show_organization_on_check_receipt' => self::normalizeBool(
                $settings['show_organization_on_check_receipt'] ?? true,
                true,
            ),
            /** When true, Hotel POS can capture a guest/customer name and print it on check receipts. Default off. */
            'enable_check_guest_name' => self::normalizeBool(
                $settings['enable_check_guest_name'] ?? false,
                false,
            ),
            'check_receipt_footer' => (string) ($settings['check_receipt_footer'] ?? 'Thank you'),
            'use_same_print_phones_for_check' => self::normalizeBool(
                $settings['use_same_print_phones_for_check'] ?? true,
                true,
            ),
            'check_print_phones' => [
                'tel1' => (string) (is_array($settings['check_print_phones'] ?? null)
                    ? ($settings['check_print_phones']['tel1'] ?? '')
                    : ''),
                'tel2' => (string) (is_array($settings['check_print_phones'] ?? null)
                    ? ($settings['check_print_phones']['tel2'] ?? '')
                    : ''),
            ],
            'pos_email_reports' => self::normalizePosEmailReports($settings['pos_email_reports'] ?? null),
        ];
    }

    /**
     * @return array{
     *   hotel_pos_grid_columns: int,
     *   hotel_pos_collect_payment: bool,
     *   hotel_pos_catalog_limit: int,
     *   stock_deduct_on_settle: bool,
     *   stock_location: string,
     *   block_settle_if_insufficient: bool,
     *   require_recipe_for_stocked_items: bool,
     *   hotel_pos_theme_template: string,
     *   pos_email_reports: array<string, mixed>
     * }
     */
    public static function defaults(): array
    {
        return [
            'hotel_pos_grid_columns' => self::GRID_COLUMNS_DEFAULT,
            'hotel_pos_collect_payment' => true,
            'hotel_pos_catalog_limit' => self::CATALOG_LIMIT_DEFAULT,
            'stock_deduct_on_settle' => false,
            'stock_location' => 'shop',
            'block_settle_if_insufficient' => true,
            'require_recipe_for_stocked_items' => false,
            'hotel_pos_theme_template' => self::THEME_TEMPLATE_DEFAULT,
            'check_receipt_copies' => 1,
            'show_outlet_on_check_receipt' => true,
            'show_organization_on_check_receipt' => true,
            'enable_check_guest_name' => false,
            'check_receipt_footer' => 'Thank you',
            'use_same_print_phones_for_check' => true,
            'check_print_phones' => ['tel1' => '', 'tel2' => ''],
            'pos_email_reports' => self::normalizePosEmailReports(null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array{
     *   enabled: bool,
     *   send_hourly: bool,
     *   send_daily: bool,
     *   send_on_settle: bool,
     *   daily_at: string,
     *   recipients: list<string>
     * }
     */
    public static function normalizePosEmailReports(?array $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $dailyAt = trim((string) ($raw['daily_at'] ?? '22:00'));
        if (! preg_match('/^\d{2}:\d{2}$/', $dailyAt)) {
            $dailyAt = '22:00';
        }

        $recipients = $raw['recipients'] ?? [];
        if (! is_array($recipients)) {
            $recipients = [];
        }
        $emails = [];
        foreach ($recipients as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $emails, true)) {
                $emails[] = $email;
            }
        }

        return [
            'enabled' => self::normalizeBool($raw['enabled'] ?? false, false),
            'send_hourly' => self::normalizeBool($raw['send_hourly'] ?? true, true),
            'send_daily' => self::normalizeBool($raw['send_daily'] ?? true, true),
            'send_on_settle' => self::normalizeBool($raw['send_on_settle'] ?? false, false),
            'daily_at' => $dailyAt,
            'recipients' => $emails,
        ];
    }

    public static function normalizeStockLocation(mixed $value): string
    {
        return $value === 'store' ? 'store' : 'shop';
    }

    public static function normalizeBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function gridColumnsForOrganization(?Organization $organization): int
    {
        return self::forOrganization($organization)['hotel_pos_grid_columns'];
    }
}
