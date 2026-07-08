<?php

namespace App\Services\Sales;

use App\Models\CartLine;
use App\Models\Product;
use App\Models\RouteModel;
use App\Models\TemporaryCart;
use App\Services\Erp\CapabilityGate;
use App\Services\Fulfillment\RouteAccessService;
use App\Services\Kra\SalesVatCalculator;
use Illuminate\Support\Collection;

class MobileRouteMarkupCheckoutService
{
    public function __construct(
        protected PosLinePricingService $pricing,
        protected RouteAccessService $routes,
    ) {}

    /**
     * Mobile carts are priced without route markup until checkout.
     *
     * @param  array<string, mixed>  $salesSettings
     */
    public function routeIdForCartPricing(TemporaryCart $cart, array $salesSettings): ?int
    {
        if ($cart->channel !== 'mobile') {
            return $cart->route_id ? (int) $cart->route_id : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $salesSettings
     */
    public function shouldApplyAtCheckout(array $salesSettings, ?int $routeId, ?int $organizationId = null): bool
    {
        if (empty($salesSettings['add_route_markup_prices']) || ! $routeId) {
            return false;
        }

        $route = $organizationId
            ? $this->routes->findForOrganization($organizationId, (int) $routeId)
            : RouteModel::query()->find($routeId);
        if (! $route) {
            return false;
        }

        return (float) $route->route_markup_price > 0;
    }

    /**
     * @param  Collection<int, CartLine>  $lines
     * @return array{
     *     lines: Collection<int, CartLine>,
     *     order_total: float,
     *     total_vat: float,
     *     meta: ?array<string, mixed>
     * }
     */
    public function prepareCheckoutLines(
        TemporaryCart $cart,
        Collection $lines,
        ?int $routeId,
        CapabilityGate $gate,
    ): array {
        $salesSettings = $gate->moduleSettings('sales');
        $organizationId = (int) ($gate->organization()?->id ?? 0) ?: null;

        if (! $this->shouldApplyAtCheckout($salesSettings, $routeId, $organizationId)) {
            return [
                'lines' => $lines,
                'order_total' => round((float) $lines->sum('amount'), 2),
                'total_vat' => round((float) $lines->sum('product_vat'), 2),
                'meta' => null,
            ];
        }

        $route = $organizationId
            ? $this->routes->findForOrganization($organizationId, (int) $routeId)
            : RouteModel::query()->find($routeId);
        if (! $route) {
            throw new \InvalidArgumentException('The selected route is not available for this organization.');
        }

        $repriced = $lines->map(function (CartLine $line) use ($routeId) {
            $product = Product::query()->find($line->product_code);
            if (! $product) {
                return $line;
            }

            $isRetail = (bool) $product->sell_on_retail && (bool) $line->on_wholesale_retail;
            [$unitPrice, $amount] = $this->pricing->resolveLineAmounts(
                $product,
                (float) $line->quantity,
                $isRetail,
                (float) ($line->discount_given ?? 0),
                $routeId,
                null,
                false,
            );

            $product->loadMissing('vat');
            $productVat = SalesVatCalculator::vatFromInclusiveGross(
                max(0, $amount),
                SalesVatCalculator::vatRateFromProduct($product),
            );

            $adjusted = clone $line;
            $adjusted->unit_price = $unitPrice;
            $adjusted->amount = $amount;
            $adjusted->product_vat = $productVat;

            return $adjusted;
        });

        return [
            'lines' => $repriced,
            'order_total' => round((float) $repriced->sum('amount'), 2),
            'total_vat' => round((float) $repriced->sum('product_vat'), 2),
            'meta' => $this->buildMeta($route),
        ];
    }

    /** @return array<string, mixed> */
    protected function buildMeta(RouteModel $route): array
    {
        $markup = (float) $route->route_markup_price;
        $routeName = trim((string) $route->route_name);

        return [
            'applied' => true,
            'route_id' => (int) $route->id,
            'route_name' => $routeName,
            'markup_per_unit' => $markup,
            'message' => sprintf(
                'Prices were adjusted to include route markup for %s.',
                $routeName !== '' ? $routeName : 'this route',
            ),
        ];
    }
}
