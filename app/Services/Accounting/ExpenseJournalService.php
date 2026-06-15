<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class ExpenseJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function postIfEnabled(Expense $expense, User $user, CapabilityGate $gate): JournalEntry|AccountingExportQueue|null
    {
        if (! $this->helper->settingEnabled($gate, 'auto_post_expenses', true)) {
            return null;
        }

        $amount = round((float) $expense->expense_amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $codes = $this->posting->defaultAccountCodes();
        $expenseAccount = $this->posting->resolveAccount($orgId, $codes['operating_expense'] ?? '5300');
        if (! $expenseAccount) {
            return null;
        }

        $expense->loadMissing('paymentMethod');
        $methodCode = strtoupper((string) ($expense->paymentMethod?->method_code ?? 'CASH'));
        $paymentCode = $this->helper->accountCodeForPaymentMethod($methodCode, $codes);
        $paymentAccount = $this->posting->resolveAccount($orgId, $paymentCode);
        if (! $paymentAccount) {
            return null;
        }

        $lines = [
            [
                'account_id' => $expenseAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => $expense->description ?: 'Operating expense',
            ],
            [
                'account_id' => $paymentAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'Payment for expense',
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'EXP-'.$expense->id,
            entryDate: $expense->expense_date?->toDateString() ?? now()->toDateString(),
            lines: $lines,
            description: 'Auto journal for expense #'.$expense->id,
            branchId: $expense->branch_id,
            referenceType: 'expense',
            referenceId: (int) $expense->id,
        );
    }
}
