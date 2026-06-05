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
            'package_type' => 'nullable|in:full_package,partial,pieces',
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
}
