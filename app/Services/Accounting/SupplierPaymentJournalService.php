<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class SupplierPaymentJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function postIfEnabled(
        SupplierPayment $payment,
        User $user,
        CapabilityGate $gate,
    ): JournalEntry|AccountingExportQueue|null {
        if (! $this->helper->settingEnabled($gate, 'auto_post_payments', true)) {
            return null;
        }

        $amount = round((float) $payment->amount_paid, 2);
        if ($amount <= 0) {
            return null;
        }

        $orgId = (int) $payment->organization_id;
        $chart = app(StandardChartOfAccounts::class);
        if (! $chart->isSeeded($orgId)) {
            $organization = \App\Models\Organization::find($orgId);
            if ($organization) {
                $chart->seedForOrganization($organization);
            }
        }

        $codes = $this->posting->defaultAccountCodes();
        $apAccount = $this->posting->resolveAccount($orgId, $codes['ap'] ?? '2000');
        if (! $apAccount) {
            return null;
        }

        $payment->loadMissing('paymentMethod');
        $methodCode = strtoupper((string) ($payment->paymentMethod?->method_code ?? 'CASH'));
        $paymentCode = $this->helper->accountCodeForPaymentMethod($methodCode, $codes);
        $paymentAccount = $this->posting->resolveAccount($orgId, $paymentCode);
        if (! $paymentAccount) {
            return null;
        }

        $supplierName = $payment->relationLoaded('supplier')
            ? ($payment->supplier?->supplier_name ?? 'Supplier')
            : 'Supplier';
        $lpoNote = $payment->lpo_no ? ' LPO #'.$payment->lpo_no : '';

        $lines = [
            [
                'account_id' => $apAccount->id,
                'debit' => $amount,
                'credit' => 0,
                'line_notes' => 'AP reduction — '.$supplierName.$lpoNote,
            ],
            [
                'account_id' => $paymentAccount->id,
                'debit' => 0,
                'credit' => $amount,
                'line_notes' => 'Supplier payment — '.$supplierName,
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'SPAY-'.$payment->id,
            entryDate: $payment->date_paid?->format('Y-m-d') ?? now()->toDateString(),
            lines: $lines,
            description: 'Supplier payment to '.$supplierName.$lpoNote,
            branchId: $user->branch_id ? (int) $user->branch_id : null,
            referenceType: 'supplier_payment',
            referenceId: (int) $payment->id,
        );
    }
}
