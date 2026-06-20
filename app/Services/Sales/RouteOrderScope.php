<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Builder;

/**
 * Route orders: mobile field sales or POS route-order mode (route_id set, channel mobile|pos).
 */
class RouteOrderScope
{
    public static function apply(Builder $query): Builder
    {
        return $query
            ->whereNotNull('route_id')
            ->whereIn('channel', ['mobile', 'pos']);
    }

    /**
     * Orders eligible for distribution loading lists and dispatch trips.
     * Mobile/POS route orders always; backoffice route orders only when enabled.
     */
    public static function applyForLoadingList(Builder $query, bool $includeNormalOrders = false): Builder
    {
        return $query->whereNotNull('route_id')->where(function (Builder $sub) use ($includeNormalOrders) {
            $sub->whereIn('channel', ['mobile', 'pos']);
            if ($includeNormalOrders) {
                $sub->orWhere('channel', 'backend');
            }
        });
    }

    public static function matches(?object $sale): bool
    {
        return self::eligibleForLoadingList($sale, false);
    }

    public static function eligibleForLoadingList(?object $sale, bool $includeNormalOrders = false): bool
    {
        if ($sale === null || empty($sale->route_id)) {
            return false;
        }

        $channel = (string) ($sale->channel ?? $sale->order_source ?? '');

        if (in_array($channel, ['mobile', 'pos'], true)) {
            return true;
        }

        return $includeNormalOrders && $channel === 'backend';
    }
}
