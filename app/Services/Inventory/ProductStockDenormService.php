<?php

namespace App\Services\Inventory;

use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Keeps products.stock_in_* aligned with current_stock for the correct branch context.
 */
class ProductStockDenormService
{
    public function syncFromCurrentStock(string $productCode, int $branchId): void
    {
        $row = DB::table('current_stock')
            ->where('product_code', $productCode)
            ->where('branch_id', $branchId)
            ->first();

        if (! $row) {
            return;
        }

        $orgId = DB::table('branches')->where('id', $branchId)->value('organization_id');
        if (! $orgId) {
            return;
        }

        $branchCount = (int) DB::table('branches')
            ->where('organization_id', $orgId)
            ->count();

        $query = Product::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $productCode);

        if ($branchCount > 1) {
            // Multi-branch: only branch-scoped catalog rows mirror branch stock.
            $query->where('branch_id', $branchId);
        } else {
            $query->where(function ($q) use ($branchId) {
                $q->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
        }

        $query->update([
            'stock_in_shop' => $row->shop_quantity,
            'stock_in_store' => $row->store_quantity,
        ]);
    }
}
