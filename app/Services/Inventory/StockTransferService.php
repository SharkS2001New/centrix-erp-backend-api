<?php

namespace App\Services\Inventory;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Models\StockMovementHistory;
use App\Models\User;
use App\Support\OrganizationIdResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StockTransferService
{
    use HandlesInventory;

    /** @var list<string> */
    public const LOCATION_DESTINATIONS = ['shop', 'store'];

    /** Non-location destinations — stock leaves inventory (outbound only). */
    /** @var list<string> */
    public const PURPOSE_DESTINATIONS = [
        'internal_use',
        'donations',
        'staff_consumption',
        'charity',
        'sample',
        'production',
        'display',
    ];

    public static function isLocationDestination(string $to): bool
    {
        return in_array($to, self::LOCATION_DESTINATIONS, true);
    }

    public static function isPurposeDestination(string $to): bool
    {
        return in_array($to, self::PURPOSE_DESTINATIONS, true);
    }

    public static function purposeLabel(string $purpose): string
    {
        return match ($purpose) {
            'internal_use' => 'internal use',
            'staff_consumption' => 'staff consumption',
            'sample' => 'sample / demo',
            'production' => 'production / manufacturing',
            'display' => 'display / merchandising',
            default => str_replace('_', ' ', $purpose),
        };
    }

    /**
     * @return array{out: \App\Models\InventoryTransaction, in: ?\App\Models\InventoryTransaction}
     */
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

        if (! in_array($from, self::LOCATION_DESTINATIONS, true)) {
            throw new InvalidArgumentException('The selected from location is invalid.');
        }

        if (self::isPurposeDestination($to)) {
            return $this->transferToPurpose($branchId, $productCode, $quantity, $from, $to, $user, $notes);
        }

        if (! self::isLocationDestination($to)) {
            throw new InvalidArgumentException('The selected to location is invalid.');
        }

        $noteText = trim((string) $notes);
        $outNote = $noteText !== ''
            ? "Transfer out to {$to}: {$noteText}"
            : "Transfer out to {$to}";
        $inNote = $noteText !== ''
            ? "Transfer in from {$from}: {$noteText}"
            : "Transfer in from {$from}";

        return DB::transaction(function () use ($branchId, $productCode, $quantity, $from, $to, $user, $outNote, $inNote, $noteText) {
            $orgId = OrganizationIdResolver::requireForBranch($branchId);
            $out = $this->postStockLedger($this->withProductUnitCost([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $from,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'quantity_change' => -abs($quantity),
                'created_by' => $user->id,
                'notes' => $outNote,
            ], $orgId));

            $in = $this->postStockLedger($this->withProductUnitCost([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $to,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'reference_id' => $out->id,
                'quantity_change' => abs($quantity),
                'created_by' => $user->id,
                'notes' => $inNote,
            ], $orgId));

            StockMovementHistory::create([
                'organization_id' => $orgId,
                'product_code' => $productCode,
                'branch_id' => $branchId,
                'quantity_moved' => $quantity,
                'from_location' => $from,
                'to_location' => $to,
                'notes' => $noteText !== '' ? $noteText : null,
                'moved_by' => $user->id,
            ]);

            app(\App\Services\Audit\OperationalAuditService::class)->logStockMovement($user, 'transfer', [
                'product_code' => $productCode,
                'branch_id' => $branchId,
                'quantity' => $quantity,
                'from_location' => $from,
                'to_location' => $to,
            ]);

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Outbound-only transfer for consumption purposes (internal use, donations, etc.).
     * Stock leaves the source location; no inbound to a phantom destination.
     *
     * @return array{out: \App\Models\InventoryTransaction, in: null}
     */
    protected function transferToPurpose(
        int $branchId,
        string $productCode,
        float $quantity,
        string $from,
        string $purpose,
        User $user,
        ?string $notes = null,
    ): array {
        $label = self::purposeLabel($purpose);
        $noteText = trim((string) $notes);
        $outNote = $noteText !== ''
            ? "Transfer out for {$label}: {$noteText}"
            : "Transfer out for {$label}";

        return DB::transaction(function () use ($branchId, $productCode, $quantity, $from, $purpose, $user, $outNote, $label) {
            $orgId = OrganizationIdResolver::requireForBranch($branchId);
            $out = $this->postStockLedger($this->withProductUnitCost([
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'stock_location' => $from,
                'transaction_type' => 'TRANSFER',
                'reference_type' => 'transfer',
                'quantity_change' => -abs($quantity),
                'created_by' => $user->id,
                'notes' => $outNote,
            ], $orgId));

            // stock_movement_history.to_location is ENUM(shop,store) — skip for purpose dests.
            app(\App\Services\Audit\OperationalAuditService::class)->logStockMovement($user, 'transfer', [
                'product_code' => $productCode,
                'branch_id' => $branchId,
                'quantity' => $quantity,
                'from_location' => $from,
                'to_location' => $purpose,
                'purpose_label' => $label,
            ]);

            return ['out' => $out, 'in' => null];
        });
    }
}
