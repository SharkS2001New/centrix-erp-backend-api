<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Vehicle;
use InvalidArgumentException;

class TripCapacityValidator
{
    public function __construct(protected LoadingListBuilder $loadingListBuilder) {}

    public function assertTripCapacity(DispatchTrip $trip, array $distributionSettings): void
    {
        if (empty($distributionSettings['enforce_vehicle_capacity'])) {
            return;
        }

        $vehicle = $trip->vehicle_id
            ? Vehicle::query()->find($trip->vehicle_id)
            : null;

        if (! $vehicle) {
            return;
        }

        $weightKg = $this->loadingListBuilder->computeTripWeightKg($trip);
        if ($vehicle->max_weight_kg && $weightKg > (float) $vehicle->max_weight_kg) {
            throw new InvalidArgumentException(sprintf(
                'Load weight %.2f kg exceeds vehicle capacity of %.2f kg.',
                $weightKg,
                (float) $vehicle->max_weight_kg,
            ));
        }

        $volumeM3 = $this->loadingListBuilder->computeTripVolumeM3($trip);
        if ($vehicle->max_volume_m3 && $volumeM3 > (float) $vehicle->max_volume_m3) {
            throw new InvalidArgumentException(sprintf(
                'Load volume %.3f m³ exceeds vehicle capacity of %.3f m³.',
                $volumeM3,
                (float) $vehicle->max_volume_m3,
            ));
        }
    }
}
