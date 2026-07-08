<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\CustomerReturn;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class ReturnJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
        protected SaleCogsCalculator $cogsCalculator,
    ) {}

    public function postIfEnabled(CustomerReturn $return, User $user, CapabilityGate $gate): JournalEntry|AccountingExportQueue|null
    {
        if (! $this->helper->settingEnabled($gate, 'auto_post_returns', true)) {
            return null;
        }

        $gross = round((float) $return->total_amount, 2);
        if ($gross <= 0) {
            return null;
        }

        $orgId = (int) $return->organization_id;
        $codes = $this->posting->accountCodes($gate);
        $salesAccount = $this->posting->resolveAccount($orgId, $codes['sales_revenue'] ?? '4000');
        if (! $salesAccount) {
            return null;
        }

        $vatRate = (float) ($gate->moduleSettings('sales')['default_tax_rate'] ?? 16);
        $vat = $vatRate > 0 ? round($gross * ($vatRate / (100 + $vatRate)), 2) : 0.0;
        $net = round($gross - $vat, 2);

        $refundMethod = strtoupper((string) ($return->refund_method ?? 'CASH'));
        $isCredit = in_array($refundMethod, ['CREDIT', 'AR', 'ACCOUNT'], true)
            || ($return->sale?->is_credit_sale && ! in_array($refundMethod, ['CASH', 'MPESA', 'CARD', 'BANK'], true));

        if ($isCredit) {
            $refundCode = $codes['ar'] ?? '1200';
        } else {
            $refundCode = $this->helper->accountCodeForPaymentMethod($refundMethod, $codes, $gate);
        }

        $refundAccount = $this->posting->resolveAccount($orgId, $refundCode);
        if (! $refundAccount) {
            return null;
        }

        $lines = [
            [
                'account_id' => $salesAccount->id,
                'debit' => $net,
                'credit' => 0,
                'line_notes' => 'Sales return',
            ],
        ];

        if ($vat > 0) {
            $vatAccount = $this->posting->resolveAccount($orgId, $codes['vat_payable'] ?? '2100');
            if (! $vatAccount) {
                return null;
            }
            $lines[] = [
                'account_id' => $vatAccount->id,
                'debit' => $vat,
                'credit' => 0,
                'line_notes' => 'VAT on return',
            ];
        }

        $lines[] = [
            'account_id' => $refundAccount->id,
            'debit' => 0,
            'credit' => $gross,
            'line_notes' => $isCredit ? 'AR credit' : 'Refund paid',
        ];

        $cogsAmount = $this->cogsCalculator->totalCostForCustomerReturn($return);
        if ($cogsAmount > 0) {
            $cogsAccount = $this->posting->resolveAccount($orgId, $codes['cogs'] ?? '5000');
            $inventoryAccount = $this->posting->resolveAccount($orgId, $codes['inventory'] ?? '1300');
            if ($cogsAccount && $inventoryAccount) {
                $lines[] = [
                    'account_id' => $inventoryAccount->id,
                    'debit' => $cogsAmount,
                    'credit' => 0,
                    'line_notes' => 'Inventory restocked on return',
                ];
                $lines[] = [
                    'account_id' => $cogsAccount->id,
                    'debit' => 0,
                    'credit' => $cogsAmount,
                    'line_notes' => 'COGS reversal on return',
                ];
            }
        }

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'RET-'.$return->return_no,
            entryDate: $return->return_date?->toDateString() ?? now()->toDateString(),
            lines: $lines,
            description: 'Auto journal for return '.$return->return_no,
            branchId: $return->branch_id,
            referenceType: 'customer_return',
            referenceId: (int) $return->id,
        );
    }
}
