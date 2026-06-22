<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustRequest;
use App\Services\Auth\UserAccessService;
use Illuminate\Http\Request;

class StockOperationsController extends Controller
{
    use HandlesInventory;

    public function __construct(protected UserAccessService $access) {}

    public function availability(Request $request)
    {
        $data = $request->validate([
            'product_code' => 'required|string|exists:products,product_code',
            'branch_id' => 'required|integer|exists:branches,id',
            'location' => 'nullable|in:shop,store',
        ]);
        $user = $request->user();
        if ($user) {
            $this->access->assertBranchAccess($user, (int) $data['branch_id']);
        }
        $loc = $data['location'] ?? 'shop';

        return response()->json([
            'product_code' => $data['product_code'],
            'branch_id' => $data['branch_id'],
            'location' => $loc,
            'on_hand' => $this->stockOnHand($data['product_code'], $data['branch_id'], $loc),
            'reserved' => $this->stockReserved($data['product_code'], $data['branch_id'], $loc),
            'available' => $this->stockNetAvailable($data['product_code'], $data['branch_id'], $loc),
        ]);
    }

    public function adjust(StockAdjustRequest $request)
    {
        $data = $request->validated();
        $user = $request->user();
        abort_unless($user, 401);

        $this->access->assertBranchAccess($user, (int) $data['branch_id']);

        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);
        $txn = $this->postStockLedger([
            ...$data,
            'transaction_type' => 'ADJUSTMENT',
            'reference_type' => 'adjustment',
            'created_by' => $user->id,
        ], $allowBelowStock);

        return response()->json($txn, 201);
    }
}
