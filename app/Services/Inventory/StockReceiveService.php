<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\LpoTxn;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockReceiveService
{
    use HandlesInventory;

    /** @param  array<string, mixed>  $data */
    public function receive(array $data, User $user): StockReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $location = $data['stock_location'] ?? 'store';
            $qty = (float) $data['units_received'];

            $txn = null;
            if (! empty($data['lpo_txn_id'])) {
                $txn = LpoTxn::query()->lockForUpdate()->find($data['lpo_txn_id']);
            }

            $costResolution = $this->resolveReceiveUnitCost($data, $txn);
            $effectiveCost = $costResolution['effective_cost'];
            $originalCost = $costResolution['original_cost'];

            $receiptAttributes = [
                'product_code' => $data['product_code'],
                'branch_id' => $data['branch_id'],
                'organization_id' => $user->organization_id,
                'units_received' => $qty,
                'stock_location' => $location,
                'invoice_number' => $data['invoice_number'] ?? null,
                'cost_price' => $effectiveCost,
                'received_by' => $user->id,
            ];

            if (
                Schema::hasColumn('stock_receipts', 'original_cost_price')
                && $originalCost !== null
                && abs((float) $originalCost - (float) ($effectiveCost ?? 0)) > 0.00005
            ) {
                $receiptAttributes['original_cost_price'] = $originalCost;
            }

            $receipt = StockReceipt::create($receiptAttributes);

            $ledgerData = $this->withProductUnitCost([
                'branch_id' => $data['branch_id'],
                'product_code' => $data['product_code'],
                'stock_location' => $location,
                'transaction_type' => 'PURCHASE',
                'reference_type' => 'stock_receipt',
                'reference_id' => $receipt->id,
                'quantity_change' => $qty,
                'unit_cost' => $effectiveCost,
                'created_by' => $user->id,
            ], (int) $user->organization_id);

            $this->postStockLedger($ledgerData);

            if ($effectiveCost !== null && (float) $effectiveCost > 0) {
                Product::query()
                    ->where('organization_id', $user->organization_id)
                    ->where('product_code', $data['product_code'])
                    ->update(['last_cost_price' => (float) $effectiveCost]);
            }

            if ($txn) {
                $this->applyLpoTxnReceive($txn, $data, $qty);
                app(\App\Services\LpoModuleService::class)
                    ->syncReceiveHeaderStatus((int) $txn->lpo_no);
            }

            return $receipt;
        });
    }

    /**
     * When receiving offer/bonus qty above the PO ordered qty, spread the paid PO value
     * across all units in this receipt so stock value ≈ ordered × original cost.
     * LPO line cost_price is never changed (kept as original PO cost for records).
     *
     * @param  array<string, mixed>  $data
     * @return array{effective_cost: float|null, original_cost: float|null, offer_qty: float, paid_qty: float}
     */
    protected function resolveReceiveUnitCost(array $data, ?LpoTxn $txn): array
    {
        $requestedCost = array_key_exists('cost_price', $data) && $data['cost_price'] !== null
            ? (float) $data['cost_price']
            : null;

        if (! $txn) {
            return [
                'effective_cost' => $requestedCost,
                'original_cost' => $requestedCost,
                'offer_qty' => 0.0,
                'paid_qty' => 0.0,
            ];
        }

        $incomingPack = isset($data['pack_qty']) && $data['pack_qty'] !== null
            ? (float) $data['pack_qty']
            : (float) $data['units_received'];

        $split = $this->splitLpoReceiveQty($txn, $incomingPack);
        $originalCost = (float) ($txn->cost_price ?? $requestedCost ?? 0);
        $paidQty = $split['on_order'];
        $offerQty = $split['bonus'];

        if ($incomingPack <= 0 || $offerQty <= 0.0001) {
            return [
                'effective_cost' => $requestedCost ?? $originalCost,
                'original_cost' => $originalCost > 0 ? $originalCost : $requestedCost,
                'offer_qty' => $offerQty,
                'paid_qty' => $paidQty,
            ];
        }

        $paidValue = $paidQty * $originalCost;
        $effectiveCost = round($paidValue / $incomingPack, 4);

        return [
            'effective_cost' => $effectiveCost,
            'original_cost' => $originalCost,
            'offer_qty' => $offerQty,
            'paid_qty' => $paidQty,
        ];
    }

    /**
     * @return array{on_order: float, bonus: float}
     */
    protected function splitLpoReceiveQty(LpoTxn $txn, float $incomingPack): array
    {
        $ordered = (float) ($txn->ordered_qty ?? 0);
        $received = (float) ($txn->received_qty ?? 0);
        $offer = (float) ($txn->offer_qty ?? 0);
        $paidReceived = max(0.0, $received - $offer);
        $roomOnOrder = max(0.0, $ordered - $paidReceived);
        $onOrder = min($incomingPack, $roomOnOrder);
        $bonus = max(0.0, $incomingPack - $onOrder);

        return [
            'on_order' => $onOrder,
            'bonus' => $bonus,
        ];
    }

    /**
     * Update LPO line received totals. Offer qty is the portion received above the ordered qty.
     * cost_price on the LPO line stays the original PO unit cost.
     *
     * @param  array<string, mixed>  $data
     */
    protected function applyLpoTxnReceive(LpoTxn $txn, array $data, float $unitsReceived): void
    {
        $incomingPack = isset($data['pack_qty']) && $data['pack_qty'] !== null
            ? (float) $data['pack_qty']
            : $unitsReceived;

        $split = $this->splitLpoReceiveQty($txn, $incomingPack);

        $txn->received_qty = (float) ($txn->received_qty ?? 0) + $incomingPack;
        $txn->offer_qty = (float) ($txn->offer_qty ?? 0) + $split['bonus'];
        $txn->save();
    }
}
