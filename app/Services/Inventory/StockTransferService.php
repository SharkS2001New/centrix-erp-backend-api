<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\StockMovementHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockTransferService
{
    use HandlesInventory;

    /** @return array{out: \App\Models\InventoryTransaction, in: \App\Models\InventoryTransaction} */
    public function transfer(
        int $branchId,
        string $productCode,
        float $quantity,
        string $from,
        string $to,
        User $user,
        ?string $notes = null,
    ): array {
        if ($from === $to) {
            throw new InvalidArgumentException('From and to locations must differ.');
        }

        $noteText = trim((string) $notes);
        $outNote = $noteText !== ''
            ? "Transfer out to {$to}: {$noteText}"
            : "Transfer out to {$to}";
        $inNote = $noteText !== ''
            ? "Transfer in from {$from}: {$noteText}"
            : "Transfer in from {$from}";

        return DB::transaction(function () use ($branchId, $productCode, $quantity, $from, $to, $user, $outNote, $inNote) {
            $out = $this->postStockLedger([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $from,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'quantity_change' => -abs($quantity),
                'created_by' => $user->id,
                'notes' => $outNote,
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
                'notes' => $inNote,
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
