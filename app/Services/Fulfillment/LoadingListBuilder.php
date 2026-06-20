<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\LoadingList;
use App\Models\LoadingListLine;
use App\Models\Organization;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Services\Erp\ErpContext;
use App\Services\Sales\RouteOrderScope;
use Illuminate\Support\Collection;

class LoadingListBuilder
{
    public function __construct(protected ErpContext $erp) {}

    /** @return array<int, int> */
    protected function eligibleSaleIdsForTrip(DispatchTrip $trip): array
    {
        $trip->loadMissing(['sales', 'branch']);
        $organizationId = $trip->branch?->organization_id
            ?? $trip->sales->first()?->organization_id;

        $includeNormalOrders = false;
        if ($organizationId) {
            $org = Organization::query()->find($organizationId);
            if ($org) {
                $settings = $this->erp->gateForOrganization($org)->distributionSettings();
                $includeNormalOrders = (bool) ($settings['include_normal_orders_in_loading_list'] ?? false);
            }
        }

        return $trip->sales
            ->filter(fn ($sale) => RouteOrderScope::eligibleForLoadingList($sale, $includeNormalOrders))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function aggregateLines(DispatchTrip $trip): array
    {
        return $this->aggregateLinesFromSaleIds($this->eligibleSaleIdsForTrip($trip));
    }

    /** @param  array<int, int|string>  $saleIds
     * @return array<int, array<string, mixed>>
     */
    public function aggregateLinesFromSaleIds(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));
        if ($saleIds === []) {
            return [];
        }

        $items = SaleItem::query()
            ->whereIn('sale_id', $saleIds)
            ->get();

        /** @var Collection<string, Collection<int, SaleItem>> $grouped */
        $grouped = $items->groupBy('product_code');
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $grouped->keys()->all())
            ->get()
            ->keyBy('product_code');

        $lines = [];
        $lineNo = 1;

        foreach ($grouped as $productCode => $productItems) {
            $product = $products->get($productCode);
            $qty = (float) $productItems->sum('quantity');
            $unitPrice = $this->resolveUnitPrice($productItems, $product);
            $uom = $product?->unit;
            $packaging = $this->buildPackaging($qty, $uom);

            $lines[] = [
                'line_no' => $lineNo++,
                'product_code' => (string) $productCode,
                'product_name' => $product?->product_name ?? (string) $productCode,
                'quantity' => $qty,
                'quantity_label' => $packaging['quantity_label'],
                'pack_breakdown' => $packaging['pack_breakdown'],
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
            ];
        }

        usort($lines, fn ($a, $b) => strcmp($a['product_name'], $b['product_name']));
        foreach ($lines as $index => &$line) {
            $line['line_no'] = $index + 1;
        }

        return $lines;
    }

    public function computeTripWeightKg(DispatchTrip $trip): float
    {
        $saleIds = $this->eligibleSaleIdsForTrip($trip);
        if ($saleIds === []) {
            return 0.0;
        }

        $items = SaleItem::query()->whereIn('sale_id', $saleIds)->get();
        $codes = $items->pluck('product_code')->unique()->values()->all();
        $weights = Product::query()
            ->whereIn('product_code', $codes)
            ->pluck('product_weight', 'product_code');

        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) ($weights[$item->product_code] ?? 0) * (float) $item->quantity;
        }

        return round($total, 3);
    }

    public function syncLoadingList(DispatchTrip $trip): LoadingList
    {
        $lines = $this->aggregateLines($trip);
        $total = array_sum(array_column($lines, 'line_total'));

        $loadingList = LoadingList::query()->firstOrNew(['trip_id' => $trip->id]);
        if (! $loadingList->exists) {
            $loadingList->branch_id = $trip->branch_id;
            $loadingList->route_id = $trip->route_id;
            $loadingList->list_date = $trip->scheduled_date;
            $loadingList->status = 'open';
        }

        if ($loadingList->status === 'open') {
            $loadingList->total_amount = $total;
            $loadingList->save();

            LoadingListLine::query()->where('loading_list_id', $loadingList->id)->delete();
            foreach ($lines as $line) {
                LoadingListLine::create(array_merge($line, ['loading_list_id' => $loadingList->id]));
            }
        }

        return $loadingList->fresh(['lines', 'route', 'trip']);
    }

    /** @param  Collection<int, SaleItem>  $items */
    protected function resolveUnitPrice(Collection $items, ?Product $product): float
    {
        $totalQty = (float) $items->sum('quantity');
        if ($totalQty <= 0) {
            return (float) ($product?->unit_price ?? 0);
        }

        $totalAmount = (float) $items->sum('amount');

        return round($totalAmount / $totalQty, 2);
    }

    /** @return array{quantity_label: string, pack_breakdown: string} */
    protected function buildPackaging(float $qty, ?Uom $uom): array
    {
        $smallLabel = $uom?->small_packaging_label ?: ($uom?->measure_name ?: 'units');
        $smallLabel = ucfirst(strtolower(trim($smallLabel)));
        $qtyFormatted = number_format($qty, 0, '.', ',');

        if (! $uom || $uom->conversion_factor <= 1) {
            return [
                'quantity_label' => "{$qtyFormatted} {$smallLabel}",
                'pack_breakdown' => '',
            ];
        }

        $unitsPerPack = (float) $uom->conversion_factor;
        $numPacks = (int) round($qty / $unitsPerPack);
        if ($numPacks <= 0) {
            $numPacks = 1;
        }

        $middleLabel = $uom->middle_packaging_label ?: 'Packs';
        $packBreakdown = sprintf(
            '%s %s x %d %s',
            number_format($unitsPerPack, 0, '.', ''),
            $smallLabel,
            $numPacks,
            ucfirst(strtolower($middleLabel)),
        );

        return [
            'quantity_label' => "{$qtyFormatted} {$smallLabel}",
            'pack_breakdown' => $packBreakdown,
        ];
    }
}
