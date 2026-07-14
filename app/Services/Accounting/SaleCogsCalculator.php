<?php

namespace App\Services\Accounting;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\Inventory\StockCostCalculation;
use Illuminate\Support\Facades\DB;

class SaleCogsCalculator
{
    public function totalCostForSale(Sale $sale, ?array $unitCostBySaleProduct = null): float
    {
        $sale->loadMissing(['items.product']);
        $total = 0.0;
        $saleId = (int) $sale->id;

        foreach ($sale->items as $item) {
            $unitCost = $unitCostBySaleProduct !== null
                ? ($unitCostBySaleProduct[$saleId][(string) $item->product_code] ?? $this->fallbackUnitCost($item))
                : $this->unitCostForItem($item, $saleId);
            $factor = StockCostCalculation::conversionFactorForProduct($item->product);
            $total += StockCostCalculation::lineCostFromBaseQuantity(
                abs((float) $item->quantity),
                $unitCost,
                $factor,
            );
        }

        return round($total, 2);
    }

    /**
     * Batch latest inventory unit costs for many sales (avoids per-line queries).
     *
     * @param  list<int>  $saleIds
     * @return array<int, array<string, float>> sale_id => [product_code => unit_cost]
     */
    public function unitCostsBySaleAndProduct(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('intval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        $rows = DB::table('inventory_transactions')
            ->select(['reference_id', 'product_code', 'unit_cost', 'id'])
            ->whereIn('reference_id', $saleIds)
            ->where('quantity_change', '<', 0)
            ->whereNotNull('unit_cost')
            ->whereIn('reference_type', ['sale', 'dispatch_trip', 'sale_line_edit'])
            ->orderByDesc('id')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $saleId = (int) $row->reference_id;
            $code = (string) $row->product_code;
            if (isset($map[$saleId][$code])) {
                continue; // keep newest (first after orderByDesc)
            }
            $cost = (float) $row->unit_cost;
            if ($cost > 0) {
                $map[$saleId][$code] = $cost;
            }
        }

        return $map;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Sale>|list<Sale>  $sales
     * @return array<int, float>
     */
    public function totalCostForSales($sales): array
    {
        $collection = $sales instanceof \Illuminate\Support\Collection
            ? $sales
            : collect($sales);
        if ($collection->isEmpty()) {
            return [];
        }

        $collection->each(fn (Sale $sale) => $sale->loadMissing(['items.product']));
        $unitCosts = $this->unitCostsBySaleAndProduct(
            $collection->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $totals = [];
        foreach ($collection as $sale) {
            $totals[(int) $sale->id] = $this->totalCostForSale($sale, $unitCosts);
        }

        return $totals;
    }

    public function totalCostForCustomerReturn(CustomerReturn $return): float
    {
        $return->loadMissing('lines');
        $total = 0.0;

        foreach ($return->lines as $line) {
            $qty = abs((float) $line->return_qty);
            if ($qty <= 0) {
                continue;
            }
            $unitCost = $this->unitCostForReturnLine($return, $line);
            $product = Product::query()
                ->with('unit')
                ->where('organization_id', $return->organization_id)
                ->where('product_code', $line->product_code)
                ->first();
            $factor = StockCostCalculation::conversionFactorForProduct($product);
            $total += StockCostCalculation::lineCostFromBaseQuantity($qty, $unitCost, $factor);
        }

        return round($total, 2);
    }

    protected function unitCostForReturnLine(CustomerReturn $return, CustomerReturnLine $line): float
    {
        if ($return->sale_id) {
            $txnCost = DB::table('inventory_transactions')
                ->where('reference_id', (int) $return->sale_id)
                ->where('product_code', $line->product_code)
                ->where('quantity_change', '<', 0)
                ->whereNotNull('unit_cost')
                ->whereIn('reference_type', ['sale', 'dispatch_trip', 'sale_line_edit'])
                ->orderByDesc('id')
                ->value('unit_cost');

            if ($txnCost !== null && (float) $txnCost > 0) {
                return (float) $txnCost;
            }
        }

        $product = Product::query()
            ->where('organization_id', $return->organization_id)
            ->where('product_code', $line->product_code)
            ->first();

        return max(0, (float) ($product?->last_cost_price ?? 0));
    }

    protected function unitCostForItem(SaleItem $item, int $saleId): float
    {
        $txnCost = DB::table('inventory_transactions')
            ->where('reference_id', $saleId)
            ->where('product_code', $item->product_code)
            ->where('quantity_change', '<', 0)
            ->whereNotNull('unit_cost')
            ->whereIn('reference_type', ['sale', 'dispatch_trip', 'sale_line_edit'])
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($txnCost !== null && (float) $txnCost > 0) {
            return (float) $txnCost;
        }

        return $this->fallbackUnitCost($item);
    }

    protected function fallbackUnitCost(SaleItem $item): float
    {
        $product = $item->product;
        if (! $product && $item->relationLoaded('sale') && $item->sale?->organization_id) {
            $product = Product::query()
                ->where('organization_id', (int) $item->sale->organization_id)
                ->where('product_code', $item->product_code)
                ->first();
        }
        $product ??= Product::query()->find($item->product_code);

        return max(0, (float) ($product?->last_cost_price ?? 0));
    }
}
