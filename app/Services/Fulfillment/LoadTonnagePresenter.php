<?php

namespace App\Services\Fulfillment;

use App\Models\Driver;
use App\Models\PickingList;
use App\Models\Product;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Line and document tonnage from product_weight (kg per stock/base unit) × quantity.
 * 1 tonne = 1000 kg.
 */
class LoadTonnagePresenter
{
    public function kgToTonnes(float $kg): float
    {
        return round($kg / 1000, 4);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{
     *     lines: array<int, array<string, mixed>>,
     *     total_weight_kg: float,
     *     total_weight_tonnes: float,
     *     missing_weight_count: int
     * }
     */
    public function applyToLines(array $lines, ?int $organizationId = null): array
    {
        $codes = [];
        foreach ($lines as $line) {
            $code = trim((string) ($line['product_code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        $weights = $this->unitWeightsByCode($codes, $organizationId);
        $totalKg = 0.0;
        $missing = 0;
        $out = [];

        foreach ($lines as $line) {
            $code = trim((string) ($line['product_code'] ?? ''));
            $qty = (float) ($line['required_qty'] ?? $line['quantity'] ?? 0);
            $unit = $code !== '' ? (float) ($weights[$code] ?? 0) : 0.0;
            $missingWeight = $qty > 0.0001 && $unit <= 0;
            $lineKg = round($unit * $qty, 3);
            if ($missingWeight) {
                $missing++;
            } else {
                $totalKg += $lineKg;
            }

            $line['product_weight'] = $unit > 0 ? $unit : null;
            $line['line_weight_kg'] = $lineKg;
            $line['line_weight_tonnes'] = $this->kgToTonnes($lineKg);
            $line['weight_missing'] = $missingWeight;
            $out[] = $line;
        }

        $totalKg = round($totalKg, 3);

        return [
            'lines' => $out,
            'total_weight_kg' => $totalKg,
            'total_weight_tonnes' => $this->kgToTonnes($totalKg),
            'missing_weight_count' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentPickingList(PickingList $pickingList, mixed $vehicle = null): array
    {
        $payload = $pickingList->toArray();
        $orgId = $pickingList->organization_id !== null ? (int) $pickingList->organization_id : null;
        $summary = $this->applyToLines($payload['lines'] ?? [], $orgId);
        $payload['lines'] = $summary['lines'];
        $payload['total_weight_kg'] = $summary['total_weight_kg'];
        $payload['total_weight_tonnes'] = $summary['total_weight_tonnes'];
        $payload['missing_weight_count'] = $summary['missing_weight_count'];

        return array_merge($payload, $this->vehicleCapacityFields($vehicle));
    }

    /**
     * @param  array<string, mixed>  $pickingList
     * @return array<string, mixed>
     */
    public function presentPickingListArray(array $pickingList, mixed $vehicle = null, ?int $organizationId = null): array
    {
        $summary = $this->applyToLines($pickingList['lines'] ?? [], $organizationId);
        $pickingList['lines'] = $summary['lines'];
        $pickingList['total_weight_kg'] = $summary['total_weight_kg'];
        $pickingList['total_weight_tonnes'] = $summary['total_weight_tonnes'];
        $pickingList['missing_weight_count'] = $summary['missing_weight_count'];

        return array_merge($pickingList, $this->vehicleCapacityFields($vehicle));
    }

    /**
     * @return array{driver: array<string, mixed>|null, vehicle: array<string, mixed>|null}
     */
    public function defaultFleetForRoute(int $routeId, ?int $organizationId = null): array
    {
        if ($routeId <= 0) {
            return ['driver' => null, 'vehicle' => null];
        }

        $query = Driver::query()
            ->with('defaultVehicle')
            ->where('default_route_id', $routeId)
            ->where('is_active', true)
            ->orderBy('id');
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $driver = $query->first();
        $vehicle = $driver?->defaultVehicle;
        if ($vehicle && $vehicle->is_active === false) {
            $vehicle = null;
        }

        return [
            'driver' => $driver ? [
                'id' => $driver->id,
                'full_name' => $driver->full_name,
                'driver_code' => $driver->driver_code,
            ] : null,
            'vehicle' => $this->vehiclePayload($vehicle),
        ];
    }

    /**
     * @return array{
     *     vehicle_max_weight_kg: float|null,
     *     vehicle_max_tonnes: float|null
     * }
     */
    public function vehicleCapacityFields(mixed $vehicle): array
    {
        $maxKg = null;
        if ($vehicle instanceof Vehicle) {
            $maxKg = $vehicle->max_weight_kg !== null ? (float) $vehicle->max_weight_kg : null;
        } elseif (is_array($vehicle)) {
            $maxKg = isset($vehicle['max_weight_kg']) && $vehicle['max_weight_kg'] !== null
                ? (float) $vehicle['max_weight_kg']
                : null;
        }

        return [
            'vehicle_max_weight_kg' => $maxKg,
            'vehicle_max_tonnes' => $maxKg !== null ? $this->kgToTonnes($maxKg) : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function vehiclePayload(?Vehicle $vehicle): ?array
    {
        if (! $vehicle) {
            return null;
        }

        return [
            'id' => $vehicle->id,
            'vehicle_name' => $vehicle->vehicle_name,
            'plate_number' => $vehicle->plate_number,
            'vehicle_code' => $vehicle->vehicle_code,
            'max_weight_kg' => $vehicle->max_weight_kg !== null ? (float) $vehicle->max_weight_kg : null,
            'max_tonnes' => $vehicle->max_weight_kg !== null
                ? $this->kgToTonnes((float) $vehicle->max_weight_kg)
                : null,
        ];
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<string, float>
     */
    protected function unitWeightsByCode(array $codes, ?int $organizationId = null): array
    {
        $codes = array_values(array_unique(array_filter($codes)));
        if ($codes === []) {
            return [];
        }

        $query = Product::query()->withTrashed()->whereIn('product_code', $codes);
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        /** @var Collection<string, Product> $products */
        $products = $query->get()->keyBy('product_code');
        $weights = [];
        foreach ($products as $code => $product) {
            $weights[(string) $code] = (float) ($product->product_weight ?? 0);
        }

        return $weights;
    }
}
