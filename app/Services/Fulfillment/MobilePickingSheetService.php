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
 * Lines combine wholesale + retail for the same product with W/R qty, prices, and line totals.
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
     * One row per product: wholesale + retail quantities, prices, and line amount.
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

            $wholesaleLabel = $wholesaleQty > 0.0001
                ? $this->stockUom->formatMixedStockDisplay($wholesaleQty, $uom)['text']
                : '';
            $retailLabel = $retailQty > 0.0001
                ? $this->stockUom->formatMixedStockDisplay($retailQty, $uom)['text']
                : '';

            $quantityParts = array_values(array_filter([$wholesaleLabel, $retailLabel], fn ($v) => $v !== ''));
            $quantityLabel = implode(', ', $quantityParts);

            $retailBreakdown = $this->buildRetailBreakdown($retailItems, $uom);
            $wholesaleUnitPrice = $this->resolveTierDisplayUnitPrice($wholesaleItems, $uom, false);
            $retailUnitPrice = $this->resolveTierDisplayUnitPrice($retailItems, $uom, true);
            $fullLabel = $this->fullPackageLabel($uom);
            $smallLabel = $this->smallPackagingLabel($uom);

            $priceParts = [];
            if ($wholesaleUnitPrice > 0) {
                $priceParts[] = 'Ksh '.$this->formatMoney($wholesaleUnitPrice).' / '.$fullLabel;
            }
            if ($retailUnitPrice > 0) {
                $priceParts[] = 'Ksh '.$this->formatMoney($retailUnitPrice).' / '.$smallLabel;
            }

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
                'wholesale_qty_label' => $wholesaleLabel,
                'retail_qty_label' => $retailLabel,
                'retail_breakdown' => $retailBreakdown,
                'pack_breakdown' => '',
                'wholesale_unit_price' => $wholesaleUnitPrice,
                'retail_unit_price' => $retailUnitPrice,
                'unit_price' => $wholesaleUnitPrice > 0 ? $wholesaleUnitPrice : $retailUnitPrice,
                'price_label' => implode(' · ', $priceParts),
                'line_total' => $lineTotal,
                'sort_qty' => $this->stockUom->fulfillmentSortQuantity(
                    $wholesaleQty + $retailQty,
                    $uom,
                ),
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

    /** @param  Collection<int, SaleItem>  $retailItems */
    protected function buildRetailBreakdown(Collection $retailItems, ?Uom $uom): string
    {
        if ($retailItems->isEmpty()) {
            return '';
        }

        $parts = [];
        foreach ($retailItems as $item) {
            $qty = (float) ($item->quantity ?? 0);
            if ($qty <= 0.0001) {
                continue;
            }
            $parts[] = $this->stockUom->formatMixedStockDisplay($qty, $uom)['text'];
        }

        if ($parts === []) {
            return '';
        }

        // Collapse identical purchase sizes: "5 kg ×2, 3 kg"
        $counts = [];
        foreach ($parts as $part) {
            $counts[$part] = ($counts[$part] ?? 0) + 1;
        }
        $collapsed = [];
        foreach ($counts as $label => $count) {
            $collapsed[] = $count > 1 ? "{$label} ×{$count}" : $label;
        }

        return implode(', ', $collapsed);
    }

    /** @param  Collection<int, SaleItem>  $items */
    protected function resolveTierDisplayUnitPrice(Collection $items, ?Uom $uom, bool $isRetail): float
    {
        if ($items->isEmpty()) {
            return 0.0;
        }

        $weighted = 0.0;
        $weight = 0.0;

        foreach ($items as $item) {
            $baseQty = (float) ($item->quantity ?? 0);
            if ($baseQty <= 0.0001) {
                continue;
            }

            $soldQty = $isRetail ? $baseQty : $this->packageCount($baseQty, $uom);
            if ($soldQty <= 0.0001) {
                continue;
            }

            $display = (float) ($item->display_unit_price ?? 0);
            if ($display <= 0) {
                $amount = (float) ($item->amount ?? 0);
                $discount = (float) ($item->discount_given ?? 0);
                $display = ($amount + $discount) / $soldQty;
            }

            $weighted += $display * $soldQty;
            $weight += $soldQty;
        }

        if ($weight <= 0.0001) {
            return 0.0;
        }

        return round($weighted / $weight, 2);
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
