<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class CustomerPaymentJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function postIfEnabled(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        float $amount,
        int $paymentMethodId,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $this->helper->settingEnabled($gate, 'auto_post_payments', true)) {
            return null;
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $orgId = (int) $sale->organization_id;
        $codes = $this->posting->defaultAccountCodes();
        $arAccount = $this->posting->resolveAccount($orgId, $codes['ar'] ?? '1200');
        if (! $arAccount) {
            return null;
        }

        $method = PaymentMethod::find($paymentMethodId);
        $methodCode = strtoupper((string) ($method?->method_code ?? 'CASH'));
        $paymentCode = $this->helper->accountCodeForPaymentMethod($methodCode, $codes);
        $paymentAccount = $this->posting->resolveAccount($orgId, $paymentCode);
        if (! $paymentAccount) {
            return null;
        }

        $lines = [
            [
                'account_id' => $paymentAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => 'Customer payment received',
            ],
            [
                'account_id' => $arAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'AR reduction for sale #'.$sale->order_num,
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'PAY-'.$sale->id.'-'.now()->format('His'),
            entryDate: now()->toDateString(),
            lines: $lines,
            description: 'Customer payment on sale #'.$sale->order_num,
            branchId: $sale->branch_id,
            referenceType: 'sale_payment',
            referenceId: (int) $sale->id,
        );
    }

    public function reverseIfEnabled(
        Sale $sale,
        User $user,
        CapabilityGate $gate,
        float $amount,
        int $paymentMethodId,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $this->helper->settingEnabled($gate, 'auto_post_payments', true)) {
            return null;
        }

        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        $orgId = (int) $sale->organization_id;
        $codes = $this->posting->defaultAccountCodes();
        $arAccount = $this->posting->resolveAccount($orgId, $codes['ar'] ?? '1200');
        if (! $arAccount) {
            return null;
        }

        $method = PaymentMethod::find($paymentMethodId);
        $methodCode = strtoupper((string) ($method?->method_code ?? 'CASH'));
        $paymentCode = $this->helper->accountCodeForPaymentMethod($methodCode, $codes);
        $paymentAccount = $this->posting->resolveAccount($orgId, $paymentCode);
        if (! $paymentAccount) {
            return null;
        }

        $lines = [
            [
                'account_id' => $arAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => 'AR restoration — payment removed',
            ],
            [
                'account_id' => $paymentAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'Customer payment reversal',
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'PAY-REV-'.$sale->id.'-'.now()->format('His'),
            entryDate: now()->toDateString(),
            lines: $lines,
            description: 'Reverse customer payment on sale #'.$sale->order_num,
            branchId: $sale->branch_id,
            referenceType: 'sale_payment',
            referenceId: (int) $sale->id,
        );
    }
}
