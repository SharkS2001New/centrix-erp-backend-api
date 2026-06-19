<?php

namespace App\Services\Sales;

use App\Models\Customer;
use InvalidArgumentException;

class MobileCheckoutLocationService
{
    /** @return array{enabled: bool, allow_offline_orders: bool, radius_metres: float} */
    public function settings(array $salesSettings): array
    {
        return [
            'enabled' => (bool) ($salesSettings['mobile_enable_checkout_location_verification'] ?? false),
            'allow_offline_orders' => (bool) ($salesSettings['mobile_allow_offline_orders'] ?? false),
            'radius_metres' => max(
                1,
                min(500, (float) ($salesSettings['mobile_checkout_location_radius_metres'] ?? 5)),
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function assertCheckoutLocation(
        string $channel,
        array $salesSettings,
        ?Customer $customer,
        array $input,
    ): array {
        $settings = $this->settings($salesSettings);
        if ($channel !== 'mobile' || ! $settings['enabled']) {
            return [];
        }

        $offline = filter_var($input['offline_order'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($offline) {
            if (! $settings['allow_offline_orders']) {
                throw new InvalidArgumentException('Offline orders are not allowed.');
            }

            return [
                'verified' => false,
                'offline_order' => true,
                'radius_metres' => $settings['radius_metres'],
            ];
        }

        if (! $customer || ! $this->customerHasLocation($customer)) {
            throw new InvalidArgumentException('Customer location is required for checkout.');
        }

        $lat = $input['checkout_latitude'] ?? null;
        $lng = $input['checkout_longitude'] ?? null;
        if ($lat === null || $lng === null) {
            throw new InvalidArgumentException('Device location is required for checkout.');
        }

        $distance = $this->distanceMetres(
            (float) $lat,
            (float) $lng,
            (float) $customer->latitude,
            (float) $customer->longitude,
        );

        if ($distance > $settings['radius_metres']) {
            throw new InvalidArgumentException(
                'Customer location must be within '.$settings['radius_metres'].' metres radius.',
            );
        }

        return [
            'verified' => true,
            'offline_order' => false,
            'distance_metres' => round($distance, 2),
            'radius_metres' => $settings['radius_metres'],
            'checkout_latitude' => (float) $lat,
            'checkout_longitude' => (float) $lng,
            'customer_latitude' => (float) $customer->latitude,
            'customer_longitude' => (float) $customer->longitude,
        ];
    }

    public function customerHasLocation(Customer $customer): bool
    {
        if ($customer->latitude === null || $customer->longitude === null) {
            return false;
        }

        return abs((float) $customer->latitude) > 0.000001
            || abs((float) $customer->longitude) > 0.000001;
    }

    public function distanceMetres(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);
        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(
            pow(sin($latDelta / 2), 2)
            + cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)
        ));

        return $angle * $earthRadius;
    }
}
