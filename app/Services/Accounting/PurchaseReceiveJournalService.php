<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\StockReceipt;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class PurchaseReceiveJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function postIfEnabled(StockReceipt $receipt, User $user, CapabilityGate $gate): JournalEntry|AccountingExportQueue|null
    {
        if (! $this->helper->settingEnabled($gate, 'auto_post_purchases', true)) {
            return null;
        }

        $qty = (float) $receipt->units_received;
        $unitCost = (float) ($receipt->cost_price ?? 0);
        $amount = round($qty * $unitCost, 2);
        if ($amount <= 0) {
            return null;
        }

        $orgId = (int) ($receipt->organization_id ?: $user->organization_id);
        $codes = $this->posting->defaultAccountCodes();
        $inventoryCode = $codes['inventory'] ?? '1300';
        $inventoryAccount = $this->posting->resolveAccount($orgId, $inventoryCode)
            ?? $this->posting->resolveAccount($orgId, $codes['cogs'] ?? '5000');
        $apAccount = $this->posting->resolveAccount($orgId, $codes['ap'] ?? '2000');

        if (! $inventoryAccount || ! $apAccount) {
            return null;
        }

        $lines = [
            [
                'account_id' => $inventoryAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => 'Stock received: '.$receipt->product_code,
            ],
            [
                'account_id' => $apAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'Accounts payable on receipt',
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'RCV-'.$receipt->id,
            entryDate: now()->toDateString(),
            lines: $lines,
            description: 'Auto journal for stock receipt #'.$receipt->id,
            branchId: $receipt->branch_id,
            referenceType: 'stock_receipt',
            referenceId: (int) $receipt->id,
        );
    }
}
