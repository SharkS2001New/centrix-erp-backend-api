<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Route orders: sales tied to a route directly or via the customer's assigned route.
 */
class RouteOrderScope
{
    public const CUSTOMER_JOIN_ALIAS = 'route_order_customers';

    public const DEFAULT_INCLUDE_NORMAL_ORDERS = true;

    /** @param  array<string, mixed>  $distributionSettings */
    public static function includeNormalOrders(array $distributionSettings): bool
    {
        if (! array_key_exists('include_normal_orders_in_loading_list', $distributionSettings)) {
            return self::DEFAULT_INCLUDE_NORMAL_ORDERS;
        }

        return (bool) $distributionSettings['include_normal_orders_in_loading_list'];
    }

    public static function effectiveRouteIdSql(): string
    {
        return 'COALESCE(sales.route_id, '.self::CUSTOMER_JOIN_ALIAS.'.route_id)';
    }

    public static function hasCustomerRouteJoin(Builder $query): bool
    {
        foreach ($query->getQuery()->joins ?? [] as $join) {
            $table = (string) ($join->table ?? '');
            if ($table === self::CUSTOMER_JOIN_ALIAS || str_contains($table, self::CUSTOMER_JOIN_ALIAS)) {
                return true;
            }
        }

        return false;
    }

    public static function withCustomerRouteJoin(Builder $query): Builder
    {
        if (self::hasCustomerRouteJoin($query)) {
            return $query;
        }

        return $query->leftJoin(
            'customers as '.self::CUSTOMER_JOIN_ALIAS,
            self::CUSTOMER_JOIN_ALIAS.'.customer_num',
            '=',
            'sales.customer_num',
        );
    }

    public static function applyChannelScope(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): Builder
    {
        return $query->where(function (Builder $sub) use ($includeNormalOrders) {
            $sub->whereIn('sales.channel', ['mobile', 'pos']);
            if ($includeNormalOrders) {
                $sub->orWhereIn('sales.channel', ['backend', 'backoffice'])
                    ->orWhereIn('sales.order_source', ['backend', 'backoffice']);
            }
        });
    }

    public static function apply(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): Builder
    {
        return self::applyForLoadingList($query, $includeNormalOrders);
    }

    /**
     * Orders eligible for distribution loading lists, dispatch trips, and route orders.
     */
    public static function applyForLoadingList(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): Builder
    {
        self::withCustomerRouteJoin($query);

        return $query
            ->whereNotNull(DB::raw(self::effectiveRouteIdSql()))
            ->where(function (Builder $sub) use ($includeNormalOrders) {
                self::applyChannelScope($sub, $includeNormalOrders);
            });
    }

    public static function applyRouteFilter(Builder $query, int $routeId): Builder
    {
        self::withCustomerRouteJoin($query);

        return $query->where(DB::raw(self::effectiveRouteIdSql()), $routeId);
    }

    public static function matches(?object $sale, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): bool
    {
        return self::eligibleForLoadingList($sale, $includeNormalOrders);
    }

    public static function effectiveRouteId(?object $sale): ?int
    {
        if ($sale === null) {
            return null;
        }

        $routeId = $sale->route_id ?? $sale->customer?->route_id ?? null;

        return $routeId ? (int) $routeId : null;
    }

    public static function eligibleForLoadingList(?object $sale, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): bool
    {
        if ($sale === null || ! self::effectiveRouteId($sale)) {
            return false;
        }

        $channel = (string) ($sale->channel ?? '');
        $orderSource = (string) ($sale->order_source ?? '');

        if (in_array($channel, ['mobile', 'pos'], true)) {
            return true;
        }

        if (! $includeNormalOrders) {
            return false;
        }

        return in_array($channel, ['backend', 'backoffice'], true)
            || in_array($orderSource, ['backend', 'backoffice'], true);
    }
}
