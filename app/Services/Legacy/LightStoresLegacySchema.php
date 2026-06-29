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
 * POS order_num is reused across dates — always scope by (order_num, create_time).
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

    /** Legacy catalog — sale line FKs reference product.product_code. */
    public const PRODUCTS = 'product';

    /** Legacy users (cashiers / sales people). */
    public const USERS = 'user';

    /**
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
        $tables = ['org_info', self::PRODUCTS];
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

    public static function posListWalkInSubquery(string $connection): Builder
    {
        return DB::connection($connection)
            ->query()
            ->fromSub(
                DB::connection($connection)
                    ->table(self::POS_WALK_IN)
                    ->selectRaw('order_no, customer_name, ROW_NUMBER() OVER (PARTITION BY order_no ORDER BY create_time DESC) AS rn'),
                'sc',
            )
            ->where('sc.rn', 1)
            ->select('sc.order_no', 'sc.customer_name');
    }

    /** List totals per POS sale day (order # is reused across dates). */
    public static function posListLineTotalsSubquery(
        string $connection,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        $query = DB::connection($connection)->table(self::POS_LINES);

        if ($fromDate || $toDate) {
            self::applyMasterDateFilter($query, 'create_time', $fromDate, $toDate);
        }

        return $query
            ->selectRaw('order_num_ref AS order_num, create_time AS sale_date, COALESCE(SUM(amount), 0) AS order_total, COALESCE(SUM(product_vat), 0) AS total_vat')
            ->groupBy('order_num_ref', 'create_time');
    }

    /** Detail header totals for one POS sale (order # + line create date). */
    public static function posOrderLineTotalsSubquery(string $connection, int $orderNum, string $saleDate): Builder
    {
        return DB::connection($connection)
            ->table(self::POS_LINES)
            ->where('order_num_ref', $orderNum)
            ->whereDate('create_time', $saleDate)
            ->selectRaw('order_num_ref AS order_num, COALESCE(SUM(amount), 0) AS order_total, COALESCE(SUM(product_vat), 0) AS total_vat')
            ->groupBy('order_num_ref');
    }

    public static function mobileListLineTotalsSubquery(
        string $connection,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Builder {
        $query = DB::connection($connection)->table(self::ROUTE_LINES);

        if ($fromDate || $toDate) {
            self::applyMasterDateFilter($query, 'create_time', $fromDate, $toDate);
        }

        return $query
            ->selectRaw('order_no AS order_num, create_time AS sale_date, COALESCE(SUM(amount), 0) AS order_total, COALESCE(SUM(product_vat), 0) AS total_vat')
            ->groupBy('order_no', 'create_time');
    }

    public static function mobileOrderLineTotalsSubquery(string $connection, int $orderNum, string $saleDate): Builder
    {
        return DB::connection($connection)
            ->table(self::ROUTE_LINES.' as rod')
            ->join(self::ROUTE_MASTERS.' as rm', function ($join) {
                $join->on('rm.order_num', '=', 'rod.order_no')
                    ->on('rm.create_time', '=', 'rod.create_time');
            })
            ->whereNull('rm.DLT_ON')
            ->where('rm.order_num', $orderNum)
            ->where('rm.create_time', $saleDate)
            ->selectRaw('rm.order_num AS order_num, COALESCE(SUM(rod.amount), 0) AS order_total, COALESCE(SUM(rod.product_vat), 0) AS total_vat')
            ->groupBy('rm.order_num');
    }

    public static function debtorLineTotalsSubquery(
        string $connection,
        ?string $fromDate = null,
        ?string $toDate = null,
        ?int $orderNum = null,
        ?string $saleDate = null,
    ): Builder {
        $query = DB::connection($connection)
            ->table(self::DEBTOR_LINES.' as dp')
            ->join(self::DEBTOR_MASTERS.' as dm', 'dm.order_num', '=', 'dp.order_num')
            ->whereNull('dm.dlt_on');

        if ($orderNum !== null && $saleDate !== null) {
            $query->where('dm.order_num', $orderNum)->whereDate('dm.create_time', $saleDate);
        } elseif ($orderNum !== null) {
            $query->where('dm.order_num', $orderNum);
        } elseif ($fromDate || $toDate) {
            self::applyMasterDateFilter($query, 'dm.create_time', $fromDate, $toDate);
        }

        return $query
            ->selectRaw('dm.order_num as order_num, DATE(dm.create_time) as sale_date, COALESCE(SUM(dp.amount), 0) as order_total, COALESCE(SUM(dp.product_vat), 0) as total_vat')
            ->groupBy('dm.order_num', DB::raw('DATE(dm.create_time)'));
    }

    protected static function applyMasterDateFilter(Builder $query, string $column, ?string $fromDate, ?string $toDate): void
    {
        if ($fromDate) {
            $query->whereDate($column, '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate($column, '<=', $toDate);
        }
    }
}
