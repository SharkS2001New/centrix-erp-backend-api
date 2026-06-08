<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Models\StockMovementHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockTransferController extends Controller
{
    use HandlesInventory;

    public function store(StockTransferRequest $request)
    {
        $data = $request->validated();
        $result = $this->transferStock(
            $data['branch_id'],
            $data['product_code'],
            $data['quantity'],
            $data['from_location'],
            $data['to_location'],
            $request->user()
        );

        return response()->json($result, 201);
    }

    protected function transferStock(
        int $branchId,
        string $productCode,
        float $quantity,
        string $from,
        string $to,
        User $user
    ): array {
        if ($from === $to) {
            throw new InvalidArgumentException('From and to locations must differ.');
        }

        return DB::transaction(function () use ($branchId, $productCode, $quantity, $from, $to, $user) {
            $out = $this->postStockLedger([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $from,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'quantity_change' => -abs($quantity),
                'created_by' => $user->id,
                'notes' => "Transfer out to {$to}",
            ]);

            $in = $this->postStockLedger([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $to,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'reference_id' => $out->id,
                'quantity_change' => abs($quantity),
                'created_by' => $user->id,
                'notes' => "Transfer in from {$from}",
            ]);

            StockMovementHistory::create([
                'product_code' => $productCode,
                'branch_id' => $branchId,
                'quantity_moved' => $quantity,
                'from_location' => $from,
                'to_location' => $to,
                'moved_by' => $user->id,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }
}
