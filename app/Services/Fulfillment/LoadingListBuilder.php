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

    /**
     * Always compute display lines from current trip orders (ignores stale DB rows).
     *
     * @return array<int, array<string, mixed>>
     */
    public function linesForTrip(DispatchTrip $trip): array
    {
        return $this->aggregateLines($trip);
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
        $grouped = $items->groupBy(
            fn (SaleItem $item) => $item->product_code.'|'.(int) ($item->on_wholesale_retail ?? 0),
        );
        $productCodes = $grouped->keys()
            ->map(fn (string $groupKey) => explode('|', $groupKey, 2)[0])
            ->unique()
            ->values()
            ->all();
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $productCodes)
            ->get()
            ->keyBy('product_code');

        $lines = [];
        $lineNo = 1;

        foreach ($grouped as $groupKey => $productItems) {
            [$productCode] = array_pad(explode('|', (string) $groupKey, 2), 2, '0');
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
                'on_wholesale_retail' => (int) ($productItems->first()->on_wholesale_retail ?? 0),
                'price_tier' => ($productItems->first()->on_wholesale_retail ?? 0) ? 'retail' : 'wholesale',
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

    public function computeTripVolumeM3(DispatchTrip $trip): float
    {
        $saleIds = $this->eligibleSaleIdsForTrip($trip);
        if ($saleIds === []) {
            return 0.0;
        }

        $items = SaleItem::query()->whereIn('sale_id', $saleIds)->get();
        $codes = $items->pluck('product_code')->unique()->values()->all();
        $volumes = Product::query()
            ->whereIn('product_code', $codes)
            ->pluck('product_volume_m3', 'product_code');

        $total = 0.0;
        foreach ($items as $item) {
            $volume = (float) ($volumes[$item->product_code] ?? 0);
            $total += $volume * (float) $item->quantity;
        }

        return round($total, 6);
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
        $smallLabel = strtolower(trim($uom?->small_packaging_label ?: ($uom?->measure_name ?: 'units')));
        $qtyFormatted = $this->formatPackQuantity($qty);

        if (! $uom || (float) $uom->conversion_factor <= 1) {
            $withUnit = trim("{$qtyFormatted} {$smallLabel}");

            return [
                'quantity_label' => $qtyFormatted,
                'pack_breakdown' => $withUnit,
            ];
        }

        $unitsPerPack = (float) $uom->conversion_factor;
        $numPacksExact = $qty / $unitsPerPack;
        $middleLabel = strtolower(trim($uom->middle_packaging_label ?: 'packs'));

        if (abs($numPacksExact - round($numPacksExact)) < 0.00001) {
            $numPacks = (int) round($numPacksExact);
            if ($numPacks <= 0 && $qty > 0) {
                $numPacks = 1;
            }
            $packLabel = trim("{$numPacks} {$middleLabel}");

            return [
                'quantity_label' => $packLabel,
                'pack_breakdown' => $packLabel,
            ];
        }

        $withUnit = trim("{$qtyFormatted} {$smallLabel}");
        $packBreakdown = sprintf(
            '%s %s x %s %s',
            $this->formatPackQuantity($unitsPerPack),
            $smallLabel,
            $this->formatPackQuantity($numPacksExact),
            $middleLabel,
        );

        return [
            'quantity_label' => $withUnit,
            'pack_breakdown' => $packBreakdown,
        ];
    }

    protected function formatPackQuantity(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.00001) {
            return number_format($qty, 0, '.', ',');
        }

        return rtrim(rtrim(number_format($qty, 2, '.', ','), '0'), '.');
    }
}
