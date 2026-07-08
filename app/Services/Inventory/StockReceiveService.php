<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StockReceiveService
{
    use HandlesInventory;

    /** @param  array<string, mixed>  $data */
    public function receive(array $data, User $user): StockReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $location = $data['stock_location'] ?? 'store';
            $qty = (float) $data['units_received'];

            $receipt = StockReceipt::create([
                'product_code' => $data['product_code'],
                'branch_id' => $data['branch_id'],
                'organization_id' => $user->organization_id,
                'units_received' => $qty,
                'stock_location' => $location,
                'invoice_number' => $data['invoice_number'] ?? null,
                'cost_price' => $data['cost_price'] ?? null,
                'received_by' => $user->id,
            ]);

            $ledgerData = $this->withProductUnitCost([
                'branch_id' => $data['branch_id'],
                'product_code' => $data['product_code'],
                'stock_location' => $location,
                'transaction_type' => 'PURCHASE',
                'reference_type' => 'stock_receipt',
                'reference_id' => $receipt->id,
                'quantity_change' => $qty,
                'unit_cost' => $data['cost_price'] ?? null,
                'created_by' => $user->id,
            ], (int) $user->organization_id);

            $this->postStockLedger($ledgerData);

            if (array_key_exists('cost_price', $data) && $data['cost_price'] !== null && (float) $data['cost_price'] > 0) {
                Product::query()
                    ->where('organization_id', $user->organization_id)
                    ->where('product_code', $data['product_code'])
                    ->update(['last_cost_price' => (float) $data['cost_price']]);
            }

            if (! empty($data['lpo_txn_id'])) {
                $txn = LpoTxn::find($data['lpo_txn_id']);
                if ($txn) {
                    $txn->received_qty = (float) ($txn->received_qty ?? 0) + $qty;
                    $txn->save();
                }
            }

            return $receipt;
        });
    }
}
