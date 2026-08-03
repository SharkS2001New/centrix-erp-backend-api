<?php

namespace App\Services\Fulfillment;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\Uom;
use App\Models\User;
use App\Services\Inventory\StockUomDisplayService;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Product-aggregated picking lists for mobile route orders when Distribution is disabled.
 * Lines keep wholesale and retail distinct (W/R qty labels + sold prices) and show a
 * per-customer retail breakdown under qty for warehouse pickers.
 */
class MobilePickingSheetService
{
    public function __construct(
        protected MobileLoadingSheetService $loadingSheets,
        protected LoadingListBuilder $loadingListBuilder,
        protected StockUomDisplayService $stockUom,
    ) {}

    public function assertAvailable(bool $distributionOpsEnabled, bool $mobileSalesEnabled): void
    {
        $this->loadingSheets->assertAvailable($distributionOpsEnabled, $mobileSalesEnabled);
    }

    /** @return array<int, array<string, mixed>> */
    public function listSheets(User $user, array $filters = []): array
    {
        return $this->loadingSheets->listSheets($user, $filters);
    }

    /** @return array<string, mixed> */
    public function sheetDetail(User $user, int $routeId, string $listDate): array
    {
        if ($routeId <= 0) {
            throw new InvalidArgumentException('Route is required.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $listDate)) {
            throw new InvalidArgumentException('Invalid picking date.');
        }

        $loadingDetail = $this->loadingSheets->sheetDetail($user, $routeId, $listDate);
        $loadingList = $loadingDetail['loading_list'] ?? [];
        $orders = $loadingDetail['orders'] ?? [];
        $saleIds = collect($orders)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $lines = $this->aggregateSalesPickingLines($saleIds);
        $orderTotalValue = round(
            collect($orders)->sum(fn ($order) => (float) ($order['order_total'] ?? 0)),
            2,
        );
        $dateToken = str_replace('-', '', $listDate);

        return [
            'picking_list' => [
                'list_number' => sprintf('PK-%s-%03d', $dateToken, $routeId),
                'list_date' => $listDate,
                'route_id' => $routeId,
                'route' => $loadingList['route'] ?? null,
                'status' => 'open',
                'layout' => 'sales',
                'order_count' => (int) ($loadingList['order_count'] ?? count($saleIds)),
                'line_count' => count($lines),
                'order_total_value' => $orderTotalValue,
                'lines' => $lines,
            ],
            'orders' => $orders,
        ];
    }

    /**
     * One row per product with explicit W/R quantities, sold unit prices (not averages),
     * and a per-customer retail qty ghost under Quantity.
     *
     * @param  array<int, int>  $saleIds
     * @return array<int, array<string, mixed>>
     */
    public function aggregateSalesPickingLines(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));
        if ($saleIds === []) {
            return [];
        }

        $items = SaleItem::query()
            ->with(['sale.customer'])
            ->whereIn('sale_id', $saleIds)
            ->get();

        if ($items->isEmpty()) {
            return [];
        }

        $productCodes = $items->pluck('product_code')->unique()->values()->all();
        $products = Product::query()
            ->with('unit')
            ->whereIn('product_code', $productCodes)
            ->get()
            ->keyBy('product_code');

        $grouped = $items->groupBy(fn (SaleItem $item) => (string) $item->product_code);
        $lines = [];
        $lineNo = 1;

        foreach ($grouped as $productCode => $productItems) {
            $product = $products->get($productCode);
            $uom = $product?->unit;
            $wholesaleItems = $productItems->filter(
                fn (SaleItem $item) => ! (int) ($item->on_wholesale_retail ?? 0),
            )->values();
            $retailItems = $productItems->filter(
                fn (SaleItem $item) => (int) ($item->on_wholesale_retail ?? 0) === 1,
            )->values();

            $wholesaleQty = (float) $wholesaleItems->sum('quantity');
            $retailQty = (float) $retailItems->sum('quantity');
            $lineTotal = round((float) $productItems->sum('amount'), 2);

            // Wholesale → pack labels (bags); retail stays in small units (kg/pcs).
            // Never convert retail kg into bags — that erases the W/R distinction.
            $wholesaleLabel = $this->formatWholesaleQtyLabel($wholesaleQty, $uom);
            $retailLabel = $this->formatRetailQtyLabel($retailQty, $uom);

            $quantityParts = [];
            if ($wholesaleLabel !== '') {
                $quantityParts[] = 'W '.$wholesaleLabel;
            }
            if ($retailLabel !== '') {
                $quantityParts[] = 'R '.$retailLabel;
            }
            $quantityLabel = implode(', ', $quantityParts);

            $retailBreakdown = $this->buildRetailCustomerBreakdown($retailItems, $uom);
            $wholesalePrices = $this->distinctSoldUnitPrices($wholesaleItems, $uom, false);
            $retailPrices = $this->distinctSoldUnitPrices($retailItems, $uom, true);
            $fullLabel = $this->fullPackageLabel($uom);
            $smallLabel = $this->smallPackagingLabel($uom);

            $priceParts = [];
            if ($wholesalePrices !== []) {
                $priceParts[] = 'W Ksh '.$this->formatPriceList($wholesalePrices).' / '.$fullLabel;
            }
            if ($retailPrices !== []) {
                $priceParts[] = 'R Ksh '.$this->formatPriceList($retailPrices).' / '.$smallLabel;
            }

            $wholesaleUnitPrice = $wholesalePrices[0] ?? 0.0;
            $retailUnitPrice = $retailPrices[0] ?? 0.0;

            $lines[] = [
                'line_no' => $lineNo++,
                'product_code' => (string) $productCode,
                'product_name' => $product?->product_name ?? (string) $productCode,
                'shelf_location' => null,
                'stock_location' => 'store',
                'required_qty' => round($wholesaleQty + $retailQty, 4),
                'picked_qty' => round($wholesaleQty + $retailQty, 4),
                'shortage_qty' => 0.0,
                'wholesale_qty' => $wholesaleQty,
                'retail_qty' => $retailQty,
                'quantity_label' => $quantityLabel,
                'wholesale_qty_label' => $wholesaleLabel !== '' ? 'W '.$wholesaleLabel : '',
                'retail_qty_label' => $retailLabel !== '' ? 'R '.$retailLabel : '',
                'retail_breakdown' => $retailBreakdown,
                'pack_breakdown' => '',
                'wholesale_unit_price' => $wholesaleUnitPrice,
                'retail_unit_price' => $retailUnitPrice,
                'wholesale_unit_prices' => $wholesalePrices,
                'retail_unit_prices' => $retailPrices,
                'unit_price' => $wholesaleUnitPrice > 0 ? $wholesaleUnitPrice : $retailUnitPrice,
                'price_label' => implode(' · ', $priceParts),
                'line_total' => $lineTotal,
                'sort_qty' => $wholesaleQty > 0.0001
                    ? $this->stockUom->fulfillmentSortQuantity($wholesaleQty, $uom)
                    : $retailQty,
                'shortage_reason' => null,
            ];
        }

        usort($lines, function ($a, $b) {
            // Highest displayed package count first (26 jer before 4 bag).
            $qtyCmp = ((float) ($b['sort_qty'] ?? 0)) <=> ((float) ($a['sort_qty'] ?? 0));
            if ($qtyCmp !== 0) {
                return $qtyCmp;
            }

            $baseCmp = ((float) ($b['required_qty'] ?? 0)) <=> ((float) ($a['required_qty'] ?? 0));
            if ($baseCmp !== 0) {
                return $baseCmp;
            }

            return strcmp((string) $a['product_name'], (string) $b['product_name']);
        });

        foreach ($lines as $index => &$line) {
            $line['line_no'] = $index + 1;
        }

        return $lines;
    }

    /**
     * Per-customer retail qty under the main R total — e.g. "John 20 kg, Mary 15 kg".
     *
     * @param  Collection<int, SaleItem>  $retailItems
     */
    protected function buildRetailCustomerBreakdown(Collection $retailItems, ?Uom $uom): string
    {
        if ($retailItems->isEmpty()) {
            return '';
        }

        /** @var array<string, float> $byCustomer */
        $byCustomer = [];
        foreach ($retailItems as $item) {
            $qty = (float) ($item->quantity ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }
            $name = $this->customerNameForItem($item);
            $byCustomer[$name] = ($byCustomer[$name] ?? 0.0) + $qty;
        }

        if ($byCustomer === []) {
            return '';
        }

        uasort($byCustomer, function ($a, $b) {
            $qtyCmp = $b <=> $a;
            if ($qtyCmp !== 0) {
                return $qtyCmp;
            }

            return 0;
        });

        $parts = [];
        foreach ($byCustomer as $name => $qty) {
            $qtyText = $this->formatRetailQtyLabel((float) $qty, $uom);
            if ($qtyText === '') {
                continue;
            }
            $parts[] = $name.' '.$qtyText;
        }

        return implode(', ', $parts);
    }

    /** Wholesale: bags / jer / packs (mixed UOM hierarchy). */
    protected function formatWholesaleQtyLabel(float $baseQty, ?Uom $uom): string
    {
        if ($baseQty <= 0.0001) {
            return '';
        }

        return $this->stockUom->formatMixedStockDisplay($baseQty, $uom)['text'];
    }

    /** Retail: always small packaging (kg / pcs) — never fold into wholesale bags. */
    protected function formatRetailQtyLabel(float $baseQty, ?Uom $uom): string
    {
        if ($baseQty <= 0.0001) {
            return '';
        }

        return $this->formatDisplayQty($baseQty).' '.$this->smallPackagingLabel($uom);
    }

    protected function formatDisplayQty(float $qty): string
    {
        if (abs($qty - round($qty)) < 0.0001) {
            return number_format((int) round($qty), 0, '.', ',');
        }

        $formatted = rtrim(rtrim(number_format($qty, 3, '.', ','), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    protected function customerNameForItem(SaleItem $item): string
    {
        $sale = $item->sale;
        if ($sale) {
            $name = trim($sale->customerDisplayName());
            if ($name !== '') {
                return $name;
            }
        }

        return 'Customer';
    }

    /**
     * Distinct sold unit prices from sale_items (display_unit_price / reverse from amount).
     * Never averages — pickers see the prices that were actually sold.
     *
     * @param  Collection<int, SaleItem>  $items
     * @return list<float>
     */
    protected function distinctSoldUnitPrices(Collection $items, ?Uom $uom, bool $isRetail): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $prices = [];
        foreach ($items as $item) {
            $price = $this->soldDisplayUnitPrice($item, $uom, $isRetail);
            if ($price <= 0) {
                continue;
            }
            $key = (string) $price;
            $prices[$key] = $price;
        }

        $list = array_values($prices);
        sort($list, SORT_NUMERIC);

        return $list;
    }

    protected function soldDisplayUnitPrice(SaleItem $item, ?Uom $uom, bool $isRetail): float
    {
        $baseQty = (float) ($item->quantity ?? 0);
        if ($baseQty <= 0.0001) {
            return 0.0;
        }

        $soldQty = $isRetail ? $baseQty : $this->packageCount($baseQty, $uom);
        if ($soldQty <= 0.0001) {
            return 0.0;
        }

        $display = (float) ($item->display_unit_price ?? 0);
        if ($display > 0) {
            return (float) (int) round($display);
        }

        $gross = (float) ($item->amount ?? 0) + max(0.0, (float) ($item->discount_given ?? 0));
        if ($gross > 0.0001) {
            return (float) (int) round($gross / $soldQty);
        }

        return 0.0;
    }

    /** @param  list<float>  $prices */
    protected function formatPriceList(array $prices): string
    {
        return implode(', ', array_map(fn (float $p) => $this->formatMoney($p), $prices));
    }

    protected function packageCount(float $baseQty, ?Uom $uom): float
    {
        $factor = (float) ($uom?->conversion_factor ?? 1);
        if ($factor <= 1) {
            return $baseQty;
        }

        return $baseQty / $factor;
    }

    protected function fullPackageLabel(?Uom $uom): string
    {
        $name = trim((string) ($uom?->full_name ?? ''));
        if ($name !== '') {
            return $name;
        }
        $middle = trim((string) ($uom?->middle_packaging_label ?? ''));

        return $middle !== '' ? $middle : 'bag';
    }

    protected function smallPackagingLabel(?Uom $uom): string
    {
        $explicit = trim((string) ($uom?->small_packaging_label ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $type = trim((string) ($uom?->uom_type ?? ''));

        return $type !== '' ? $type : 'kg';
    }

    protected function formatMoney(float $amount): string
    {
        if (abs($amount - round($amount)) < 0.0001) {
            return number_format((int) round($amount), 0, '.', ',');
        }

        return number_format($amount, 2, '.', ',');
    }
}
