<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Models\ReturnRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReturnOperationsController extends Controller
{
    use HandlesInventory;

    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => 'nullable|integer',
            'branch_id' => 'required|integer',
            'product_code' => 'required|string',
            'quantity' => 'required|numeric|min:0.001',
            'uom' => 'nullable|string',
            'amount' => 'required|numeric',
            'reason' => 'nullable|string',
            'return_type' => 'required|in:CURRENT,PREVIOUS,MOBILE,SUPPLIER',
            'stock_location' => 'nullable|in:shop,store',
        ]);

        return DB::transaction(function () use ($data, $request) {
            $return = ReturnRecord::create([
                ...$data,
                'returned_by' => $request->user()->id,
                'is_mobile' => $data['return_type'] === 'MOBILE' ? 1 : 0,
            ]);

            $this->postStockLedger([
                'branch_id' => $data['branch_id'],
                'product_code' => $data['product_code'],
                'stock_location' => $data['stock_location'] ?? 'shop',
                'transaction_type' => 'RETURN',
                'reference_type' => 'return',
                'reference_id' => $return->id,
                'quantity_change' => abs((float) $data['quantity']),
                'created_by' => $request->user()->id,
            ]);

            return response()->json($return, 201);
        });
    }
}
