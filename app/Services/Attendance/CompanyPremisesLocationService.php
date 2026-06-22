<?php

namespace App\Services\Attendance;

use App\Models\Organization;
use InvalidArgumentException;

class CompanyPremisesLocationService
{
    public function __construct(
        protected AttendanceBranchPremisesService $branchPremises,
        protected \App\Services\Sales\MobileCheckoutLocationService $distance,
    ) {}

    /** @param  array<string, mixed>  $settings */
    public function assertWithinPremises(
        Organization $organization,
        int $branchId,
        array $settings,
        float $latitude,
        float $longitude,
    ): array {
        $premises = $this->branchPremises->forBranch($organization, $branchId);
        if (! $premises) {
            throw new InvalidArgumentException('Company premises location is not configured for this branch.');
        }

        $distanceMetres = $this->distance->distanceMetres(
            $latitude,
            $longitude,
            $premises['latitude'],
            $premises['longitude'],
        );

        $radius = (float) $premises['radius_metres'];
        if ($distanceMetres > $radius) {
            throw new InvalidArgumentException(
                'You must be within '.$radius.' metres of the branch premises to mark attendance.',
            );
        }

        return [
            'verified' => true,
            'distance_metres' => round($distanceMetres, 2),
            'radius_metres' => $radius,
            'premises_latitude' => $premises['latitude'],
            'premises_longitude' => $premises['longitude'],
            'branch_id' => $branchId,
        ];
    }
}
