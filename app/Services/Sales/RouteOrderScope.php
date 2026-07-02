<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Builder;

/**
 * Route orders: sales with route_id from mobile, POS, or (by default) backoffice checkout.
 */
class RouteOrderScope
{
    public const DEFAULT_INCLUDE_NORMAL_ORDERS = true;

    /** @param  array<string, mixed>  $distributionSettings */
    public static function includeNormalOrders(array $distributionSettings): bool
    {
        if (! array_key_exists('include_normal_orders_in_loading_list', $distributionSettings)) {
            return self::DEFAULT_INCLUDE_NORMAL_ORDERS;
        }

        return (bool) $distributionSettings['include_normal_orders_in_loading_list'];
    }

    public static function apply(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): Builder
    {
        return self::applyForLoadingList($query, $includeNormalOrders);
    }

    /**
     * Orders eligible for distribution loading lists, dispatch trips, and route orders.
     * Mobile/POS route orders always; backoffice route orders when enabled (default on).
     */
    public static function applyForLoadingList(Builder $query, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): Builder
    {
        return $query->whereNotNull('route_id')->where(function (Builder $sub) use ($includeNormalOrders) {
            $sub->whereIn('channel', ['mobile', 'pos']);
            if ($includeNormalOrders) {
                $sub->orWhereIn('channel', ['backend', 'backoffice'])
                    ->orWhereIn('order_source', ['backend', 'backoffice']);
            }
        });
    }

    public static function matches(?object $sale, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): bool
    {
        return self::eligibleForLoadingList($sale, $includeNormalOrders);
    }

    public static function eligibleForLoadingList(?object $sale, bool $includeNormalOrders = self::DEFAULT_INCLUDE_NORMAL_ORDERS): bool
    {
        if ($sale === null || empty($sale->route_id)) {
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
