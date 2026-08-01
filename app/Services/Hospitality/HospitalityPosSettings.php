<?php

namespace App\Services\Hospitality;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class HospitalityPosSettings
{
    public const GRID_COLUMNS_DEFAULT = 4;

    public const GRID_COLUMNS_ALLOWED = [4, 5];

    public const CATALOG_LIMIT_DEFAULT = 30;

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

    /**
     * @return array{
     *   hotel_pos_grid_columns: int,
     *   hotel_pos_collect_payment: bool,
     *   hotel_pos_catalog_limit: int,
     *   stock_deduct_on_settle: bool,
     *   stock_location: string,
     *   block_settle_if_insufficient: bool,
     *   require_recipe_for_stocked_items: bool
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
     *   require_recipe_for_stocked_items: bool
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
