<?php

namespace App\Services\Legacy;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Canonical LightStores legacy MySQL table map — verified against LightStoresDBBackup.sql.
 *
 * POS:     sale_masters + sale_products + sale_customers (walk-in names)
 * Route:   route_master + route_order_details + customer
 * Debtor:  debtor_masters + debtor_products + customer
 *
 * Order totals are SUM(amount) and SUM(product_vat) on the line tables.
 */
class LightStoresLegacySchema
{
    public const POS_MASTERS = 'sale_masters';

    public const POS_LINES = 'sale_products';

    public const POS_WALK_IN = 'sale_customers';

    public const ROUTE_MASTERS = 'route_master';

    public const ROUTE_LINES = 'route_order_details';

    public const DEBTOR_MASTERS = 'debtor_masters';

    public const DEBTOR_LINES = 'debtor_products';

    /** Registered customers (route + debtor), not walk-in POS names. */
    public const CUSTOMERS = 'customer';

    /**
     * Sales archive tables required in the legacy MySQL database (from LightStoresDBBackup.sql).
     *
     * @return array<string, array{role: string, channel: string|null, tables: list<string>}>
     */
    public static function salesChannelGroups(): array
    {
        return [
            'pos' => [
                'role' => 'POS counter sales',
                'channel' => 'pos',
                'tables' => [self::POS_MASTERS, self::POS_LINES, self::POS_WALK_IN],
            ],
            'route' => [
                'role' => 'Route / mobile sales',
                'channel' => 'mobile',
                'tables' => [self::ROUTE_MASTERS, self::ROUTE_LINES, self::CUSTOMERS],
            ],
            'debtor' => [
                'role' => 'Debtor / credit sales',
                'channel' => 'debtor',
                'tables' => [self::DEBTOR_MASTERS, self::DEBTOR_LINES, self::CUSTOMERS],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function requiredArchiveTables(): array
    {
        $tables = ['org_info', 'product'];
        foreach (self::salesChannelGroups() as $group) {
            foreach ($group['tables'] as $table) {
                $tables[] = $table;
            }
        }

        return array_values(array_unique($tables));
    }

    public static function legacySourceForChannel(string $channel): string
    {
        return match ($channel) {
            'mobile' => self::ROUTE_MASTERS,
            'debtor' => self::DEBTOR_MASTERS,
            default => self::POS_MASTERS,
        };
    }

    /**
     * @return list<string>
     */
    public static function legacySourcesForChannel(string $channel): array
    {
        return [self::legacySourceForChannel($channel)];
    }

    public static function posLineTotalsSubquery(string $connection): Builder
    {
        return DB::connection($connection)
            ->table(self::POS_LINES)
            ->selectRaw('order_num_ref as order_num, COALESCE(SUM(amount), 0) as order_total, COALESCE(SUM(product_vat), 0) as total_vat')
            ->groupBy('order_num_ref');
    }

    public static function debtorLineTotalsSubquery(string $connection): Builder
    {
        return DB::connection($connection)
            ->table(self::DEBTOR_LINES)
            ->selectRaw('order_num, COALESCE(SUM(amount), 0) as order_total, COALESCE(SUM(product_vat), 0) as total_vat')
            ->groupBy('order_num');
    }

    public static function routeLineTotalsSubquery(string $connection): Builder
    {
        return DB::connection($connection)
            ->table(self::ROUTE_LINES)
            ->selectRaw('order_no as order_num, COALESCE(SUM(amount), 0) as order_total, COALESCE(SUM(product_vat), 0) as total_vat')
            ->groupBy('order_no');
    }
}
