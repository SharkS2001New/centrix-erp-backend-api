<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\Damage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DamageController extends BaseResourceController
{
    use HandlesInventory;

    protected function modelClass(): string
    {
        return Damage::class;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_code' => 'required|string|exists:products,product_code',
            'branch_id' => 'required|integer|exists:branches,id',
            'quantity' => 'required|numeric|min:0.001',
            'package_type' => 'nullable|in:full_package,partial,pieces,full,middle,small',
            'uom_label' => 'nullable|string|max:45',
            'stock_location' => 'nullable|in:shop,store',
            'reason' => 'nullable|string|max:2000',
        ]);

        $location = $data['stock_location'] ?? 'shop';
        $qty = (float) $data['quantity'];

        $damage = DB::transaction(function () use ($data, $location, $qty, $request) {
            $damage = Damage::create([
                'product_code' => $data['product_code'],
                'branch_id' => $data['branch_id'],
                'quantity' => $qty,
                'package_type' => $data['package_type'] ?? 'partial',
                'uom_label' => $data['uom_label'] ?? null,
                'stock_location' => $location,
                'reason' => $data['reason'] ?? null,
                'reported_by' => $request->user()->id,
            ]);

            $this->postStockLedger([
                'branch_id' => $data['branch_id'],
                'product_code' => $data['product_code'],
                'stock_location' => $location,
                'transaction_type' => 'DAMAGE',
                'reference_type' => 'damage',
                'reference_id' => $damage->id,
                'quantity_change' => -abs($qty),
                'notes' => $data['reason'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            return $damage;
        });

        return response()->json($damage, 201);
    }

    public function update(Request $request, string $id)
    {
        $damage = Damage::query()->findOrFail($id);
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'package_type' => 'nullable|in:full_package,partial,pieces,full,middle,small',
            'uom_label' => 'nullable|string|max:45',
            'stock_location' => 'nullable|in:shop,store',
            'reason' => 'nullable|string|max:2000',
        ]);

        $userId = (int) $request->user()->id;
        $oldQty = (float) $damage->quantity;
        $oldLocation = $damage->stock_location ?? 'shop';
        $newQty = (float) $data['quantity'];
        $newLocation = $data['stock_location'] ?? $oldLocation;
        $stockChanged = abs($oldQty - $newQty) > 0.0001 || $oldLocation !== $newLocation;

        $damage = DB::transaction(function () use ($damage, $data, $userId, $oldQty, $oldLocation, $newQty, $newLocation, $stockChanged) {
            if ($stockChanged) {
                $this->postStockLedger([
                    'branch_id' => (int) $damage->branch_id,
                    'product_code' => $damage->product_code,
                    'stock_location' => $oldLocation,
                    'transaction_type' => 'ADJUSTMENT',
                    'reference_type' => 'damage_reversal',
                    'reference_id' => (int) $damage->id,
                    'quantity_change' => abs($oldQty),
                    'notes' => 'Edit reversal: damage #'.$damage->id,
                    'created_by' => $userId,
                ]);

                $this->postStockLedger([
                    'branch_id' => (int) $damage->branch_id,
                    'product_code' => $damage->product_code,
                    'stock_location' => $newLocation,
                    'transaction_type' => 'DAMAGE',
                    'reference_type' => 'damage',
                    'reference_id' => (int) $damage->id,
                    'quantity_change' => -abs($newQty),
                    'notes' => $data['reason'] ?? null,
                    'created_by' => $userId,
                ]);
            }

            $damage->update([
                'quantity' => $newQty,
                'package_type' => $data['package_type'] ?? $damage->package_type,
                'uom_label' => array_key_exists('uom_label', $data) ? $data['uom_label'] : $damage->uom_label,
                'stock_location' => $newLocation,
                'reason' => array_key_exists('reason', $data) ? $data['reason'] : $damage->reason,
            ]);

            return $damage->fresh();
        });

        return response()->json($damage);
    }

    public function destroy(string $id)
    {
        $damage = Damage::query()->findOrFail($id);
        $userId = (int) request()->user()->id;

        DB::transaction(function () use ($damage, $userId) {
            $this->postStockLedger([
                'branch_id' => (int) $damage->branch_id,
                'product_code' => $damage->product_code,
                'stock_location' => $damage->stock_location ?? 'shop',
                'transaction_type' => 'ADJUSTMENT',
                'reference_type' => 'damage_reversal',
                'reference_id' => (int) $damage->id,
                'quantity_change' => abs((float) $damage->quantity),
                'notes' => 'Reversal: '.($damage->reason ?? 'damage #'.$damage->id),
                'created_by' => $userId,
            ]);

            $damage->delete();
        });

        return response()->json(null, 204);
    }
}
