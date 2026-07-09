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
    public function totalCostForSale(Sale $sale): float
    {
        $sale->loadMissing(['items.product']);
        $total = 0.0;

        foreach ($sale->items as $item) {
            $unitCost = $this->unitCostForItem($item, (int) $sale->id);
            $factor = StockCostCalculation::conversionFactorForProduct($item->product);
            $total += StockCostCalculation::lineCostFromBaseQuantity(
                abs((float) $item->quantity),
                $unitCost,
                $factor,
            );
        }

        return round($total, 2);
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
