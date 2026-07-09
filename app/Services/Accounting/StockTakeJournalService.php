<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\StockTakeSession;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Inventory\StockCostCalculation;
use Illuminate\Support\Collection;

class StockTakeJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    /**
     * @param  Collection<int, \App\Models\StockTakeLine>  $lines
     */
    public function postIfEnabled(
        StockTakeSession $session,
        Collection $lines,
        User $user,
        CapabilityGate $gate,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $this->helper->settingEnabled($gate, 'auto_post_stock_adjustments', true)) {
            return null;
        }

        $journalLines = $this->buildVarianceLines($lines, (int) $user->organization_id);
        if ($journalLines === []) {
            return null;
        }

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: (int) $user->organization_id,
            entryNumber: 'STK-'.$session->id,
            entryDate: now()->toDateString(),
            lines: $journalLines,
            description: 'Stock take adjustment #'.$session->session_code,
            branchId: $session->branch_id,
            referenceType: 'stock_take_session',
            referenceId: (int) $session->id,
        );
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildVarianceLines(Collection $lines, int $orgId): array
    {
        $codes = $this->posting->defaultAccountCodes();
        $cogsAccount = $this->posting->resolveAccount($orgId, $codes['cogs'] ?? '5000');
        $inventoryAccount = $this->posting->resolveAccount($orgId, $codes['inventory'] ?? '1300');

        if (! $cogsAccount || ! $inventoryAccount) {
            return [];
        }

        $shrinkage = 0.0;
        $surplus = 0.0;

        foreach ($lines as $line) {
            $variance = (float) $line->counted_quantity - (float) $line->system_quantity;
            if (abs($variance) < 0.0001) {
                continue;
            }

            $product = Product::query()
                ->with('unit')
                ->where('organization_id', $orgId)
                ->where('product_code', $line->product_code)
                ->first();
            $unitCost = max(0, (float) ($product?->last_cost_price ?? 0));
            $factor = StockCostCalculation::conversionFactorForProduct($product);
            $amount = StockCostCalculation::lineCostFromBaseQuantity(abs($variance), $unitCost, $factor);
            if ($amount <= 0) {
                continue;
            }

            if ($variance < 0) {
                $shrinkage += $amount;
            } else {
                $surplus += $amount;
            }
        }

        $net = round($surplus - $shrinkage, 2);
        if (abs($net) < 0.01) {
            return [];
        }

        if ($net > 0) {
            return [
                [
                    'account_id' => $inventoryAccount->id,
                    'debit' => $net,
                    'credit' => 0,
                    'line_notes' => 'Stock take surplus',
                ],
                [
                    'account_id' => $cogsAccount->id,
                    'debit' => 0,
                    'credit' => $net,
                    'line_notes' => 'Stock take surplus (COGS relief)',
                ],
            ];
        }

        $amount = abs($net);

        return [
            [
                'account_id' => $cogsAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => 'Stock take shrinkage',
            ],
            [
                'account_id' => $inventoryAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'Stock take shrinkage (inventory relief)',
            ],
        ];
    }
}
