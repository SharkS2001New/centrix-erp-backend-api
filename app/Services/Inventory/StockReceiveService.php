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
                    $this->applyLpoTxnReceive($txn, $data, $qty);
                }
            }

            return $receipt;
        });
    }

    /**
     * Update LPO line received totals. Offer qty is the portion received above the ordered qty.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyLpoTxnReceive(LpoTxn $txn, array $data, float $unitsReceived): void
    {
        $incomingPack = isset($data['pack_qty']) && $data['pack_qty'] !== null
            ? (float) $data['pack_qty']
            : $unitsReceived;

        $ordered = (float) ($txn->ordered_qty ?? 0);
        $received = (float) ($txn->received_qty ?? 0);
        $offer = (float) ($txn->offer_qty ?? 0);
        $paidReceived = max(0.0, $received - $offer);
        $roomOnOrder = max(0.0, $ordered - $paidReceived);
        $onOrder = min($incomingPack, $roomOnOrder);
        $bonus = max(0.0, $incomingPack - $onOrder);

        $txn->received_qty = $received + $incomingPack;
        $txn->offer_qty = $offer + $bonus;
        $txn->save();
    }
}
