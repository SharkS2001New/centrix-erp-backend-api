<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class InventoryMovementJournalService
{
    public const MOVEMENT_SHRINKAGE = 'shrinkage';

    public const MOVEMENT_INCREASE = 'increase';

    public const MOVEMENT_SUPPLIER_RETURN = 'supplier_return';

    public const MOVEMENT_SUPPLIER_RETURN_REVERSAL = 'supplier_return_reversal';

    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function amountFromQtyCost(float $qty, ?float $unitCost): ?float
    {
        $amount = round(abs($qty) * max(0, (float) ($unitCost ?? 0)), 2);

        return $amount > 0 ? $amount : null;
    }

    public function unitCostForProduct(int $orgId, string $productCode): ?float
    {
        $lastCost = Product::query()
            ->where('organization_id', $orgId)
            ->where('product_code', $productCode)
            ->value('last_cost_price');

        $cost = max(0, (float) ($lastCost ?? 0));

        return $cost > 0 ? $cost : null;
    }

    public function postIfEnabled(
        CapabilityGate $gate,
        User $user,
        string $movementType,
        float $amount,
        string $entryNumber,
        string $description,
        ?int $branchId,
        string $referenceType,
        int $referenceId,
        ?string $entryDate = null,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $this->helper->settingEnabled($gate, 'auto_post_stock_adjustments', true)) {
            return null;
        }

        $lines = $this->buildLines((int) $user->organization_id, $movementType, $amount);
        if ($lines === []) {
            return null;
        }

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: (int) $user->organization_id,
            entryNumber: $entryNumber,
            entryDate: $entryDate ?? now()->toDateString(),
            lines: $lines,
            description: $description,
            branchId: $branchId,
            referenceType: $referenceType,
            referenceId: $referenceId,
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildLines(int $orgId, string $movementType, float $amount): array
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return [];
        }

        $codes = $this->posting->defaultAccountCodes();
        $inventory = $this->posting->resolveAccount($orgId, $codes['inventory'] ?? '1300');
        $cogs = $this->posting->resolveAccount($orgId, $codes['cogs'] ?? '5000');
        $ap = $this->posting->resolveAccount($orgId, $codes['ap'] ?? '2000');

        return match ($movementType) {
            self::MOVEMENT_SHRINKAGE => ($cogs && $inventory) ? [
                [
                    'account_id' => $cogs->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'line_notes' => 'Inventory shrinkage (COGS)',
                ],
                [
                    'account_id' => $inventory->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'line_notes' => 'Inventory relief',
                ],
            ] : [],
            self::MOVEMENT_INCREASE => ($cogs && $inventory) ? [
                [
                    'account_id' => $inventory->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'line_notes' => 'Inventory increase',
                ],
                [
                    'account_id' => $cogs->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'line_notes' => 'Inventory increase (COGS offset)',
                ],
            ] : [],
            self::MOVEMENT_SUPPLIER_RETURN => ($ap && $inventory) ? [
                [
                    'account_id' => $ap->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'line_notes' => 'Supplier return (AP relief)',
                ],
                [
                    'account_id' => $inventory->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'line_notes' => 'Inventory returned to supplier',
                ],
            ] : [],
            self::MOVEMENT_SUPPLIER_RETURN_REVERSAL => ($ap && $inventory) ? [
                [
                    'account_id' => $inventory->id,
                    'debit' => $amount,
                    'credit' => 0,
                    'line_notes' => 'Supplier return reversal (restock)',
                ],
                [
                    'account_id' => $ap->id,
                    'debit' => 0,
                    'credit' => $amount,
                    'line_notes' => 'Supplier return reversal (AP)',
                ],
            ] : [],
            default => [],
        };
    }
}
