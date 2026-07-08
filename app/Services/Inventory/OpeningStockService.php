<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\User;
use App\Services\Auth\UserAccessService;

/**
 * Records opening balances when a product is first created (products.manage).
 * Uses the inventory ledger so POS, reports, and stock levels stay aligned.
 */
class OpeningStockService
{
    use HandlesInventory;

    public function __construct(protected UserAccessService $access) {}

    /**
     * @param  array{branch_id: int, shop_quantity?: float|int|null, store_quantity?: float|int|null}  $opening
     */
    public function applyOnProductCreate(User $user, string $productCode, int $productId, array $opening): void
    {
        $branchId = (int) $opening['branch_id'];
        $this->access->assertBranchAccess($user, $branchId);

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $shopQty = (float) ($opening['shop_quantity'] ?? 0);
        $storeQty = (float) ($opening['store_quantity'] ?? 0);

        foreach (['shop' => $shopQty, 'store' => $storeQty] as $location => $qty) {
            if ($qty <= 0) {
                continue;
            }

            $this->postStockLedger($this->withProductUnitCost([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $location,
                'quantity_change' => $qty,
                'transaction_type' => 'ADJUSTMENT',
                'reference_type' => 'opening_balance',
                'reference_id' => $productId,
                'notes' => 'Opening stock on product create',
                'created_by' => $user->id,
            ], (int) $user->organization_id), $allowBelowStock);
        }
    }
}
