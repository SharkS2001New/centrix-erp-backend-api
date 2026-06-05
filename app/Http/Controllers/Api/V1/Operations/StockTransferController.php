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
            $request->user(),
            $data['purpose'] ?? null,
            $data['notes'] ?? null,
        );

        return response()->json($result, 201);
    }

    protected function transferStock(
        int $branchId,
        string $productCode,
        float $quantity,
        string $from,
        string $to,
        User $user,
        ?string $purpose = null,
        ?string $notes = null,
    ): array {
        $consumptionDestinations = [
            'internal_use', 'donations', 'staff_consumption', 'charity', 'sample', 'production', 'display',
        ];
        $isConsumption = in_array($to, $consumptionDestinations, true);

        if (! $isConsumption && $from === $to) {
            throw new InvalidArgumentException('From and to locations must differ.');
        }

        $destLabel = str_replace('_', ' ', $to);
        $purposeNote = $purpose ? " · {$purpose}" : '';
        $extra = $notes ? " — {$notes}" : '';

        return DB::transaction(function () use (
            $branchId, $productCode, $quantity, $from, $to, $user,
            $purposeNote, $extra, $isConsumption, $destLabel,
        ) {
            if ($isConsumption) {
                $out = $this->postStockLedger([
                    'branch_id' => $branchId,
                    'product_code' => $productCode,
                    'stock_location' => $from,
                    'transaction_type' => 'TRANSFER',
                    'reference_type' => 'transfer',
                    'quantity_change' => -abs($quantity),
                    'created_by' => $user->id,
                    'notes' => "Transfer out to {$destLabel}{$purposeNote}{$extra}",
                ]);

                StockMovementHistory::create([
                    'product_code' => $productCode,
                    'branch_id' => $branchId,
                    'quantity_moved' => $quantity,
                    'from_location' => $from,
                    'to_location' => $to,
                    'moved_by' => $user->id,
                ]);

                return ['out' => $out];
            }

            $out = $this->postStockLedger([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $from,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'quantity_change' => -abs($quantity),
                'created_by' => $user->id,
                'notes' => "Transfer out to {$to}{$purposeNote}{$extra}",
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
                'notes' => "Transfer in from {$from}{$purposeNote}{$extra}",
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
