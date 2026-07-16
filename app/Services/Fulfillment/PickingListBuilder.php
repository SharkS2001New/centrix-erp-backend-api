<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\PickingList;
use App\Models\PickingListLine;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockUomDisplayService;
use Illuminate\Support\Collection;

class PickingListBuilder
{
    public function __construct(
        protected LoadingListBuilder $loadingListBuilder,
        protected ErpContext $erp,
        protected StockUomDisplayService $stockUom,
    ) {}

    public function generateListNumber(int $branchId, string $date): string
    {
        $prefix = 'PK-'.str_replace('-', '', $date);
        $count = PickingList::query()
            ->where('branch_id', $branchId)
            ->where('list_number', 'like', "{$prefix}-%")
            ->count();

        return sprintf('%s-%03d', $prefix, $count + 1);
    }

    /** @return array<int, array<string, mixed>> */
    public function aggregateLines(DispatchTrip $trip): array
    {
        $saleIds = $this->loadingListBuilder->eligibleSaleIdsForTrip($trip);
        if ($saleIds === []) {
            return [];
        }

        $items = SaleItem::query()->whereIn('sale_id', $saleIds)->get();
        $productCodes = $items->pluck('product_code')->unique()->values()->all();
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $productCodes)
            ->get()
            ->keyBy('product_code');

        $pickLocation = $this->resolvePickStockLocation($trip);
        $shelfEnabled = $this->productShelfLocationEnabledForTrip($trip);

        /** @var Collection<string, Collection<int, SaleItem>> $grouped */
        $grouped = $items->groupBy(fn (SaleItem $item) => (string) $item->product_code);

        $lines = [];
        foreach ($grouped as $productCode => $productItems) {
            $product = $products->get($productCode);
            $requiredQty = (float) $productItems->sum('quantity');
            $uom = $product?->unit;
            $packaging = $this->buildPackaging($requiredQty, $uom);
            $shelfLocation = $shelfEnabled
                ? (trim((string) ($product?->shelf_location ?? '')) ?: null)
                : null;

            $lines[] = [
                'product_code' => (string) $productCode,
                'product_name' => $product?->product_name ?? (string) $productCode,
                'shelf_location' => $shelfLocation,
                'stock_location' => $pickLocation,
                'required_qty' => $requiredQty,
                'picked_qty' => 0.0,
                'shortage_qty' => 0.0,
                'quantity_label' => $packaging['quantity_label'],
                'pack_breakdown' => $packaging['pack_breakdown'],
                'shortage_reason' => null,
            ];
        }

        usort($lines, function ($a, $b) use ($shelfEnabled) {
            if ($shelfEnabled) {
                $shelfA = strtoupper((string) ($a['shelf_location'] ?? 'ZZZ'));
                $shelfB = strtoupper((string) ($b['shelf_location'] ?? 'ZZZ'));
                $cmp = strcmp($shelfA, $shelfB);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return strcmp($a['product_name'], $b['product_name']);
        });

        foreach ($lines as $index => &$line) {
            $line['line_no'] = $index + 1;
        }

        return $lines;
    }

    public function syncPickingList(DispatchTrip $trip): PickingList
    {
        $lines = $this->aggregateLines($trip);

        $pickingList = PickingList::query()->firstOrNew(['trip_id' => $trip->id]);
        if (! $pickingList->exists) {
            $pickingList->organization_id = $trip->organization_id
                ?? \App\Support\OrganizationIdResolver::requireForBranch((int) $trip->branch_id);
            $pickingList->branch_id = $trip->branch_id;
            $pickingList->route_id = $trip->route_id;
            $pickingList->list_date = $trip->scheduled_date;
            $pickingList->list_number = $this->generateListNumber(
                (int) $trip->branch_id,
                (string) $trip->scheduled_date,
            );
            $pickingList->status = 'open';
        }

        if (! in_array($pickingList->status, ['open', 'completed'], true)) {
            return $pickingList->fresh(['lines', 'route', 'trip']);
        }

        $pickingList->save();

        $existingPicked = PickingListLine::query()
            ->where('picking_list_id', $pickingList->id)
            ->get()
            ->keyBy('product_code');

        PickingListLine::query()->where('picking_list_id', $pickingList->id)->delete();

        foreach ($lines as $line) {
            $existing = $existingPicked->get($line['product_code']);
            $pickedQty = $existing ? (float) $existing->picked_qty : (float) $line['required_qty'];
            $requiredQty = (float) $line['required_qty'];
            $shortageQty = max(0, round($requiredQty - $pickedQty, 4));

            PickingListLine::create([
                'picking_list_id' => $pickingList->id,
                'line_no' => $line['line_no'],
                'product_code' => $line['product_code'],
                'product_name' => $line['product_name'],
                'shelf_location' => $line['shelf_location'],
                'stock_location' => $line['stock_location'],
                'required_qty' => $requiredQty,
                'picked_qty' => $pickedQty,
                'shortage_qty' => $shortageQty,
                'quantity_label' => $line['quantity_label'],
                'pack_breakdown' => $line['pack_breakdown'],
                'shortage_reason' => $existing?->shortage_reason,
            ]);
        }

        return $pickingList->fresh(['lines', 'route', 'trip']);
    }

    /** @param  array<int, array<string, mixed>>  $lineUpdates */
    public function updatePickedQuantities(PickingList $pickingList, array $lineUpdates): PickingList
    {
        if (! in_array($pickingList->status, ['open', 'completed'], true)) {
            throw new \InvalidArgumentException('Picking list is locked and cannot be edited.');
        }

        $linesById = $pickingList->lines()->get()->keyBy('id');
        $linesByCode = $pickingList->lines()->get()->keyBy('product_code');

        foreach ($lineUpdates as $update) {
            $line = null;
            if (! empty($update['id'])) {
                $line = $linesById->get((int) $update['id']);
            } elseif (! empty($update['product_code'])) {
                $line = $linesByCode->get((string) $update['product_code']);
            }
            if (! $line) {
                continue;
            }

            $pickedQty = max(0, (float) ($update['picked_qty'] ?? $line->picked_qty));
            $requiredQty = (float) $line->required_qty;
            $shortageQty = max(0, round($requiredQty - $pickedQty, 4));

            $line->update([
                'picked_qty' => $pickedQty,
                'shortage_qty' => $shortageQty,
                'shortage_reason' => isset($update['shortage_reason'])
                    ? trim((string) $update['shortage_reason']) ?: null
                    : $line->shortage_reason,
            ]);
        }

        return $pickingList->fresh(['lines', 'route', 'trip']);
    }

    public function completePickingList(PickingList $pickingList, ?string $pickerName = null): PickingList
    {
        if ($pickingList->status === 'locked') {
            throw new \InvalidArgumentException('Picking list is already locked.');
        }

        $pickingList->update([
            'status' => 'completed',
            'picker_name' => $pickerName ? trim($pickerName) : $pickingList->picker_name,
            'completed_at' => now(),
        ]);

        return $pickingList->fresh(['lines', 'route', 'trip']);
    }

    public function lockPickingList(PickingList $pickingList): PickingList
    {
        if ($pickingList->status === 'locked') {
            return $pickingList;
        }

        $pickingList->update([
            'status' => 'locked',
            'locked_at' => now(),
        ]);

        return $pickingList->fresh(['lines', 'route', 'trip']);
    }

    protected function resolvePickStockLocation(DispatchTrip $trip): string
    {
        $trip->loadMissing('branch');
        $organizationId = $trip->branch?->organization_id;
        if (! $organizationId) {
            return 'store';
        }

        $org = Organization::query()->find($organizationId);
        if (! $org) {
            return 'store';
        }

        $settings = $this->erp->gateForOrganization($org)->moduleSettings('inventory');

        return in_array($settings['default_distribution_sale_location'] ?? 'store', ['shop', 'store'], true)
            ? $settings['default_distribution_sale_location']
            : 'store';
    }

    protected function productShelfLocationEnabledForTrip(DispatchTrip $trip): bool
    {
        $trip->loadMissing('branch');
        $organizationId = $trip->branch?->organization_id;
        if (! $organizationId) {
            return false;
        }

        $org = Organization::query()->find($organizationId);

        return $org ? $this->erp->gateForOrganization($org)->productShelfLocationEnabled() : false;
    }

    /** @return array{quantity_label: string, pack_breakdown: string} */
    protected function buildPackaging(float $qty, ?Uom $uom): array
    {
        return $this->stockUom->fulfillmentQuantityLabels($qty, $uom);
    }
}
