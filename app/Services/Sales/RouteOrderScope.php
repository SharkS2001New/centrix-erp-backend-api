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

    public static function includePosRouteOrders(bool $externalPosEnabled): bool
    {
        return $externalPosEnabled;
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
            function ($join) {
                $join->on(self::CUSTOMER_JOIN_ALIAS.'.customer_num', '=', 'sales.customer_num')
                    ->whereColumn(self::CUSTOMER_JOIN_ALIAS.'.organization_id', 'sales.organization_id');
            },
        );
    }

    public static function applyChannelScope(
        Builder $query,
        bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS,
        bool $includePosOrders = true,
    ): Builder {
        return $query->where(function (Builder $sub) use ($includeNormalOrders, $includePosOrders) {
            $sub->where('sales.channel', 'mobile');
            if ($includePosOrders) {
                $sub->orWhere('sales.channel', 'pos');
            }
            if ($includeNormalOrders) {
                $sub->orWhere(function (Builder $backoffice) {
                    $backoffice->whereIn('sales.channel', ['backend', 'backoffice', 'whatsapp'])
                        ->orWhereIn('sales.order_source', ['backend', 'backoffice', 'whatsapp']);
                });
            }
        });
    }

    public static function apply(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS, bool $includePosOrders = true): Builder
    {
        return self::applyForLoadingList($query, $includeNormalOrders, $includePosOrders);
    }

    /**
     * Orders eligible for distribution loading lists, dispatch trips, and route orders.
     */
    public static function applyForLoadingList(
        Builder $query,
        bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS,
        bool $includePosOrders = true,
    ): Builder {
        self::withCustomerRouteJoin($query);

        return $query
            ->whereNotNull(DB::raw(self::effectiveRouteIdSql()))
            ->where(function (Builder $sub) use ($includeNormalOrders, $includePosOrders) {
                self::applyChannelScope($sub, $includeNormalOrders, $includePosOrders);
            });
    }

    public static function applyRouteFilter(Builder $query, int $routeId): Builder
    {
        self::withCustomerRouteJoin($query);

        return $query->where(DB::raw(self::effectiveRouteIdSql()), $routeId);
    }

    public static function matches(?object $sale, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS, bool $includePosOrders = true): bool
    {
        return self::eligibleForLoadingList($sale, $includeNormalOrders, $includePosOrders);
    }

    public static function effectiveRouteId(?object $sale): ?int
    {
        if ($sale === null) {
            return null;
        }

        $routeId = $sale->route_id ?? $sale->customer?->route_id ?? null;

        return $routeId ? (int) $routeId : null;
    }

    public static function eligibleForLoadingList(
        ?object $sale,
        bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS,
        bool $includePosOrders = true,
    ): bool {
        if ($sale === null || ! self::effectiveRouteId($sale)) {
            return false;
        }

        $channel = (string) ($sale->channel ?? '');
        $orderSource = (string) ($sale->order_source ?? '');

        if ($channel === 'mobile') {
            return true;
        }

        if ($channel === 'pos') {
            return $includePosOrders;
        }

        if (! $includeNormalOrders) {
            return false;
        }

        return in_array($channel, ['backend', 'backoffice', 'whatsapp'], true)
            || in_array($orderSource, ['backend', 'backoffice', 'whatsapp'], true);
    }

    /**
     * Shop Debtors lists: till / backoffice credit sales, not route or mobile.
     *
     * Match A/R (is_credit_sale / CREDIT method), not customer_type=debtor —
     * POS credit to a regular customer must still appear, and a cash sale to a
     * debtor customer must not land on Paid Debtors.
     */
    public static function applyShopDebtors(Builder $query): Builder
    {
        self::withCustomerRouteJoin($query);

        $alias = self::CUSTOMER_JOIN_ALIAS;

        return $query
            ->whereNotNull('sales.customer_num')
            ->where(function (Builder $sub) {
                $sub->whereNull('sales.route_id')->orWhere('sales.route_id', 0);
            })
            ->where(function (Builder $sub) {
                $sub->whereNull('sales.channel')
                    ->orWhereNotIn('sales.channel', ['mobile']);
            })
            ->where(function (Builder $sub) {
                $sub->whereNull('sales.order_source')
                    ->orWhere('sales.order_source', '!=', 'mobile');
            })
            ->where(function (Builder $sub) {
                $sub->where('sales.is_credit_sale', 1)
                    ->orWhereRaw('UPPER(TRIM(COALESCE(sales.payment_method_code, ?))) = ?', ['', 'CREDIT']);
            })
            ->where(function (Builder $sub) use ($alias) {
                $sub->whereNull($alias.'.customer_type')
                    ->orWhere($alias.'.customer_type', '!=', 'route');
            });
    }
}
