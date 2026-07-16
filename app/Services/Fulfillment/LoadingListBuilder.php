<?php

namespace App\Services\Fulfillment;

use App\Exceptions\MissingProductWeightsException;
use App\Models\DispatchTrip;
use App\Models\LoadingList;
use App\Models\LoadingListLine;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Models\User;
use App\Services\Erp\ErpContext;
use App\Services\Inventory\StockUomDisplayService;
use App\Services\Sales\RouteOrderScope;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class LoadingListBuilder
{
    public function __construct(
        protected ErpContext $erp,
        protected StockUomDisplayService $stockUom,
    ) {}

    /** @return array<int, int> */
    public function eligibleSaleIdsForTrip(DispatchTrip $trip): array
    {
        $trip->loadMissing(['sales.customer', 'branch']);
        $organizationId = $trip->branch?->organization_id
            ?? $trip->sales->first()?->organization_id;

        $includeNormalOrders = RouteOrderScope::DEFAULT_INCLUDE_NORMAL_ORDERS;
        if ($organizationId) {
            $org = Organization::query()->find($organizationId);
            if ($org) {
                $settings = $this->erp->gateForOrganization($org)->distributionSettings();
                $includeNormalOrders = RouteOrderScope::includeNormalOrders($settings);
            }
        }

        return $trip->sales
            ->filter(fn ($sale) => RouteOrderScope::eligibleForLoadingList($sale, $includeNormalOrders))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** @return array<int, array<string, mixed>> Product totals aggregated across the trip (warehouse sync / totals). */
    public function aggregateLines(DispatchTrip $trip): array
    {
        return $this->aggregateLinesFromSaleIds($this->eligibleSaleIdsForTrip($trip));
    }

    /** @return array<int, array<string, mixed>> Loading list grouped by customer order (door loading sequence). */
    public function ordersForTrip(DispatchTrip $trip): array
    {
        $trip->loadMissing(['sales.customer', 'sales.items']);

        $eligibleIds = collect($this->eligibleSaleIdsForTrip($trip))->flip();

        $sales = $trip->sales
            ->filter(fn (Sale $sale) => $eligibleIds->has($sale->id))
            ->sortBy([
                fn (Sale $sale) => (int) ($sale->pivot?->stop_seq ?? 9999),
                fn (Sale $sale) => (int) $sale->order_num,
                fn (Sale $sale) => (int) $sale->id,
            ])
            ->values();

        return $this->buildOrdersFromSales($sales);
    }

    /** @return array<int, array<string, mixed>> @deprecated Use ordersForTrip() for loading list display. */
    public function linesForTrip(DispatchTrip $trip): array
    {
        return $this->aggregateLines($trip);
    }

    /** @param  array<int, int|string>  $saleIds
     * @return array<int, array<string, mixed>>
     */
    public function aggregateOrdersFromSaleIds(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));
        if ($saleIds === []) {
            return [];
        }

        $sales = Sale::query()
            ->whereIn('id', $saleIds)
            ->with(['customer:customer_num,customer_name', 'items'])
            ->orderBy('order_num')
            ->orderBy('id')
            ->get();

        return $this->buildOrdersFromSales($sales);
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

        return $this->buildProductLinesFromItems($items);
    }

    /**
     * @param  Collection<int, Sale>|iterable<int, Sale>  $sales
     * @return array<int, array<string, mixed>>
     */
    protected function buildOrdersFromSales(iterable $sales): array
    {
        $collection = $sales instanceof Collection ? $sales : collect($sales);
        if ($collection->isEmpty()) {
            return [];
        }

        $productCodes = $collection
            ->flatMap(fn (Sale $sale) => $sale->items->pluck('product_code'))
            ->unique()
            ->values()
            ->all();

        $products = $productCodes === []
            ? collect()
            : Product::query()
                ->with('unit')
                ->whereIn('product_code', $productCodes)
                ->get()
                ->keyBy('product_code');

        $orders = [];
        $stopNo = 1;

        foreach ($collection as $sale) {
            $lines = $this->buildProductLinesFromItems($sale->items, $products);
            $subtotal = round(array_sum(array_column($lines, 'line_total')), 2);
            $customerName = trim((string) (
                $sale->customer_name_override
                ?: ($sale->customer?->customer_name ?? '')
            ));

            $orders[] = [
                'stop_no' => (int) ($sale->pivot?->stop_seq ?? $stopNo),
                'sale_id' => (int) $sale->id,
                'order_num' => $sale->order_num,
                'customer_num' => $sale->customer_num,
                'customer_name' => $customerName !== '' ? $customerName : 'Customer #'.($sale->customer_num ?? $sale->id),
                'order_total' => round((float) $sale->order_total, 2),
                'payment_status' => $sale->payment_status,
                'subtotal' => $subtotal,
                'lines' => $lines,
            ];
            $stopNo++;
        }

        usort($orders, fn ($a, $b) => ($a['stop_no'] <=> $b['stop_no']) ?: ($a['order_num'] <=> $b['order_num']));

        return array_values($orders);
    }

    /**
     * @param  Collection<int, SaleItem>|iterable<int, SaleItem>  $items
     * @param  Collection<string, Product>|null  $products
     * @return array<int, array<string, mixed>>
     */
    protected function buildProductLinesFromItems(iterable $items, ?Collection $products = null): array
    {
        $collection = $items instanceof Collection ? $items : collect($items);
        if ($collection->isEmpty()) {
            return [];
        }

        if ($products === null) {
            $productCodes = $collection->pluck('product_code')->unique()->values()->all();
            $products = Product::query()
                ->with('unit')
                ->whereIn('product_code', $productCodes)
                ->get()
                ->keyBy('product_code');
        }

        /** @var Collection<string, Collection<int, SaleItem>> $grouped */
        $grouped = $collection->groupBy(
            fn (SaleItem $item) => $item->product_code.'|'.(int) ($item->on_wholesale_retail ?? 0),
        );

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
        $organizationId = $trip->branch?->organization_id
            ?? $trip->sales->first()?->organization_id;

        return $this->computeItemsWeightKg($items, $organizationId);
    }

    public function computeSaleWeightKg(Sale $sale): float
    {
        $items = $sale->relationLoaded('items')
            ? $sale->items
            : SaleItem::query()->where('sale_id', $sale->id)->get();

        return $this->computeItemsWeightKg($items, $sale->organization_id);
    }

    /**
     * @return array{
     *     ready: bool,
     *     total_weight_kg: float,
     *     missing_products: array<int, array{product_code: string, product_name: string, quantity: float, product_weight: float|null}>
     * }
     */
    public function saleWeightStatus(Sale $sale): array
    {
        $items = $sale->relationLoaded('items')
            ? $sale->items
            : SaleItem::query()->where('sale_id', $sale->id)->get();

        if ($items->isEmpty()) {
            return [
                'ready' => false,
                'total_weight_kg' => 0.0,
                'missing_products' => [],
            ];
        }

        $codes = $items->pluck('product_code')->unique()->values()->all();
        $products = $this->productsForWeightLookup($codes, $sale->organization_id);

        $qtyByCode = [];
        foreach ($items as $item) {
            $code = (string) $item->product_code;
            $qtyByCode[$code] = ($qtyByCode[$code] ?? 0.0) + (float) $item->quantity;
        }

        $missingProducts = [];
        $totalWeight = 0.0;
        foreach ($qtyByCode as $code => $quantity) {
            $product = $products->get($code);
            $unitWeight = (float) ($product?->product_weight ?? 0);
            $lineWeight = $unitWeight * $quantity;
            $totalWeight += $lineWeight;

            if ($unitWeight <= 0) {
                $missingProducts[] = [
                    'product_code' => $code,
                    'product_name' => (string) ($product?->product_name ?: $code),
                    'quantity' => round($quantity, 3),
                    'product_weight' => $product?->product_weight !== null ? (float) $product->product_weight : null,
                ];
            }
        }

        usort($missingProducts, fn ($a, $b) => strcmp($a['product_name'], $b['product_name']));

        return [
            'ready' => $totalWeight > 0 && $missingProducts === [],
            'total_weight_kg' => round($totalWeight, 3),
            'missing_products' => $missingProducts,
        ];
    }

    /**
     * Persist kg-per-unit weights for products on an order.
     *
     * @param  array<int|string, mixed>  $entries
     * @return array{
     *     ready: bool,
     *     total_weight_kg: float,
     *     missing_products: array<int, array{product_code: string, product_name: string, quantity: float, product_weight: float|null}>
     * }
     */
    public function updateSaleProductWeights(Sale $sale, array $entries, User $user): array
    {
        $orderCodes = SaleItem::query()
            ->where('sale_id', $sale->id)
            ->pluck('product_code')
            ->map(fn ($code) => (string) $code)
            ->unique()
            ->values()
            ->all();

        if ($orderCodes === []) {
            throw new InvalidArgumentException('Cannot set weights on an order with no line items.');
        }

        $normalized = $this->normalizeWeightEntries($entries);
        if ($normalized === []) {
            throw new InvalidArgumentException('Provide at least one product weight with a product code.');
        }

        foreach ($normalized as $entry) {
            $code = $entry['product_code'];
            if (! in_array($code, $orderCodes, true)) {
                throw new InvalidArgumentException("Product {$code} is not on this order.");
            }

            $product = Product::query()
                ->withTrashed()
                ->where('product_code', $code)
                ->where('organization_id', $sale->organization_id)
                ->first();

            if (! $product) {
                throw new InvalidArgumentException("Product {$code} was not found in the catalog.");
            }

            $product->update([
                'product_weight' => $entry['product_weight'],
                'updated_by' => $user->id,
            ]);
        }

        $sale->unsetRelation('items');

        return $this->saleWeightStatus($sale->fresh(['items']));
    }

    public function assertSaleHasLoadWeight(Sale $sale): void
    {
        $items = $sale->relationLoaded('items')
            ? $sale->items
            : SaleItem::query()->where('sale_id', $sale->id)->get();

        if ($items->isEmpty()) {
            throw new InvalidArgumentException('Cannot load an order with no line items.');
        }

        $status = $this->saleWeightStatus($sale);
        if ($status['ready']) {
            return;
        }

        $missing = $status['missing_products'];
        $preview = collect($missing)
            ->map(fn (array $row) => "{$row['product_code']} ({$row['product_name']})")
            ->take(3)
            ->implode(', ');
        $suffix = count($missing) > 3 ? sprintf(' and %d more', count($missing) - 3) : '';

        throw new MissingProductWeightsException(
            'Set product weight (kg per unit) so order tonnage can be calculated'
            .($preview !== '' ? ": {$preview}{$suffix}." : '.'),
            $missing,
            $status['total_weight_kg'],
        );
    }

    /** @param  Collection<int, SaleItem>|iterable<int, SaleItem>  $items */
    public function computeItemsWeightKg(iterable $items, ?int $organizationId = null): float
    {
        $collection = $items instanceof Collection ? $items : collect($items);
        if ($collection->isEmpty()) {
            return 0.0;
        }

        $codes = $collection->pluck('product_code')->unique()->values()->all();
        $weights = $this->productsForWeightLookup($codes, $organizationId)
            ->map(fn (Product $product) => (float) ($product->product_weight ?? 0));

        $total = 0.0;
        foreach ($collection as $item) {
            $total += (float) ($weights[$item->product_code] ?? 0) * (float) $item->quantity;
        }

        return round($total, 3);
    }

    /**
     * @param  array<int, string>  $codes
     * @return Collection<string, Product>
     */
    protected function productsForWeightLookup(array $codes, ?int $organizationId = null): Collection
    {
        if ($codes === []) {
            return collect();
        }

        $query = Product::query()->withTrashed()->whereIn('product_code', $codes);
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->get()->keyBy('product_code');
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
            $loadingList->organization_id = $trip->organization_id
                ?? \App\Support\OrganizationIdResolver::requireForBranch((int) $trip->branch_id);
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
                $payload = array_merge($line, ['loading_list_id' => $loadingList->id]);
                if (! Schema::hasColumn('loading_list_lines', 'on_wholesale_retail')) {
                    unset($payload['on_wholesale_retail']);
                }
                LoadingListLine::create($payload);
            }
        }

        return $loadingList->fresh(['lines', 'route', 'trip']);
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return array<int, array{product_code: string, product_weight: float}>
     */
    protected function normalizeWeightEntries(array $entries): array
    {
        $normalized = [];

        foreach ($entries as $key => $entry) {
            if (! is_array($entry)) {
                if (is_string($key) && $key !== '' && is_numeric($entry)) {
                    $normalized[] = [
                        'product_code' => (string) $key,
                        'product_weight' => max(0, (float) $entry),
                    ];
                }

                continue;
            }

            $code = trim((string) (
                $entry['product_code']
                ?? $entry['productCode']
                ?? $entry['code']
                ?? (is_string($key) && ! is_numeric($key) ? $key : '')
            ));

            $weight = $entry['product_weight']
                ?? $entry['productWeight']
                ?? $entry['weight']
                ?? null;

            if ($code === '' || $weight === null || $weight === '') {
                continue;
            }

            $normalized[] = [
                'product_code' => $code,
                'product_weight' => max(0, (float) $weight),
            ];
        }

        return $normalized;
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
        return $this->stockUom->fulfillmentQuantityLabels($qty, $uom);
    }
}
