<?php

namespace App\Services\Accounting;

use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;

class SaleCogsCalculator
{
    public function totalCostForSale(Sale $sale): float
    {
        $sale->loadMissing(['items.product']);
        $total = 0.0;

        foreach ($sale->items as $item) {
            $unitCost = $this->unitCostForItem($item, (int) $sale->id);
            $total += abs((float) $item->quantity) * $unitCost;
        }

        return round($total, 2);
    }

    protected function unitCostForItem(SaleItem $item, int $saleId): float
    {
        $txnCost = DB::table('inventory_transactions')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->where('product_code', $item->product_code)
            ->where('quantity_change', '<', 0)
            ->whereNotNull('unit_cost')
            ->orderByDesc('id')
            ->value('unit_cost');

        if ($txnCost !== null && (float) $txnCost > 0) {
            return (float) $txnCost;
        }

        $product = $item->product ?? Product::query()->find($item->product_code);

        return max(0, (float) ($product?->last_cost_price ?? 0));
    }
}
