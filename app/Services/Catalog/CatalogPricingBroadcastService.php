<?php

namespace App\Services\Catalog;

use App\Events\OrgCatalogPricingUpdated;
use Illuminate\Support\Facades\Broadcast;

class CatalogPricingBroadcastService
{
    public function enabled(): bool
    {
        $connection = (string) config('broadcasting.default', 'null');

        return $connection !== '' && $connection !== 'null';
    }

    /**
     * Notify External POS clients that catalogue prices / markups changed.
     *
     * @param  array{
     *     reason?: string,
     *     message?: string,
     *     product_code?: string|null,
     *     product_name?: string|null,
     *     route_id?: int|null,
     *     route_name?: string|null
     * }  $payload
     */
    public function notify(int $organizationId, array $payload = []): void
    {
        if (! $this->enabled() || $organizationId <= 0) {
            return;
        }

        try {
            Broadcast::event(new OrgCatalogPricingUpdated($organizationId, $payload));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function notifyProductPriceChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
    ): void {
        $label = trim((string) ($productName ?: $productCode));
        $this->notify($organizationId, [
            'reason' => 'product_price',
            'product_code' => $productCode,
            'product_name' => $productName,
            'message' => $label !== ''
                ? "Price updated: {$label}"
                : 'Product prices were updated.',
        ]);
    }

    public function notifyMarkupChanged(
        int $organizationId,
        string $productCode,
        ?string $productName = null,
    ): void {
        $label = trim((string) ($productName ?: $productCode));
        $this->notify($organizationId, [
            'reason' => 'markup',
            'product_code' => $productCode,
            'product_name' => $productName,
            'message' => $label !== ''
                ? "Markup updated: {$label}"
                : 'Product markups were updated.',
        ]);
    }

    public function notifyRouteMarkupChanged(
        int $organizationId,
        int $routeId,
        ?string $routeName = null,
    ): void {
        $label = trim((string) ($routeName ?: "Route #{$routeId}"));
        $this->notify($organizationId, [
            'reason' => 'route_markup',
            'route_id' => $routeId,
            'route_name' => $routeName,
            'message' => "Route markup updated: {$label}",
        ]);
    }
}
