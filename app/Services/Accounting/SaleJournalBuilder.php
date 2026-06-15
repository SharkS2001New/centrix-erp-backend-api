<?php

namespace App\Services\Accounting;

use App\Models\Sale;
use App\Models\User;

class SaleJournalBuilder
{
    public function __construct(
        protected JournalPostingService $posting,
        protected SaleCogsCalculator $cogsCalculator,
    ) {}

    /** @return array<int, array<string, mixed>>|null */
    public function buildLines(Sale $sale): ?array
    {
        $orgId = (int) $sale->organization_id;
        $codes = $this->posting->defaultAccountCodes();

        $salesAccount = $this->posting->resolveAccount($orgId, $codes['sales_revenue'] ?? '4000');
        if (! $salesAccount) {
            return null;
        }

        $gross = round((float) $sale->order_total, 2);
        $vat = round((float) $sale->total_vat, 2);
        $net = round($gross - $vat, 2);

        if ($gross <= 0) {
            return null;
        }

        $lines = [];
        $debitTotal = 0.0;

        $sale->loadMissing(['payments.paymentMethod']);

        if ($sale->payments->isNotEmpty()) {
            $byAccount = [];
            foreach ($sale->payments as $payment) {
                $methodCode = strtoupper((string) ($payment->paymentMethod?->method_code ?? 'CASH'));
                $accountCode = $this->accountCodeForPaymentMethod($methodCode, $codes);
                $account = $this->posting->resolveAccount($orgId, $accountCode);
                if (! $account) {
                    return null;
                }
                $byAccount[$account->id] = ($byAccount[$account->id] ?? 0) + round((float) $payment->amount, 2);
            }

            foreach ($byAccount as $accountId => $amount) {
                if ($amount <= 0) {
                    continue;
                }
                $lines[] = [
                    'account_id' => $accountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'line_notes' => 'Payment collected',
                ];
                $debitTotal += $amount;
            }
        } elseif ((float) $sale->amount_paid > 0) {
            $methodCode = strtoupper((string) ($sale->payment_method_code ?? 'CASH'));
            $accountCode = $this->accountCodeForPaymentMethod($methodCode, $codes);
            $account = $this->posting->resolveAccount($orgId, $accountCode);
            if (! $account) {
                return null;
            }
            $paid = round((float) $sale->amount_paid, 2);
            $lines[] = [
                'account_id' => $account->id,
                'debit' => $paid,
                'credit' => 0,
                'line_notes' => 'Payment collected',
            ];
            $debitTotal += $paid;
        }

        $arBalance = round($gross - $debitTotal, 2);
        if ($arBalance > 0.01 || ($sale->is_credit_sale && $arBalance > 0)) {
            $arAccount = $this->posting->resolveAccount($orgId, $codes['ar'] ?? '1200');
            if (! $arAccount) {
                return null;
            }
            $lines[] = [
                'account_id' => $arAccount->id,
                'debit' => max(0, $arBalance),
                'credit' => 0,
                'line_notes' => 'Accounts receivable',
            ];
            $debitTotal += max(0, $arBalance);
        }

        $lines[] = [
            'account_id' => $salesAccount->id,
            'debit' => 0,
            'credit' => $net,
            'line_notes' => 'Sales revenue',
        ];

        if ($vat > 0) {
            $vatAccount = $this->posting->resolveAccount($orgId, $codes['vat_payable'] ?? '2100');
            if (! $vatAccount) {
                return null;
            }
            $lines[] = [
                'account_id' => $vatAccount->id,
                'debit' => 0,
                'credit' => $vat,
                'line_notes' => 'VAT payable',
            ];
        }

        $cogsAmount = $this->cogsCalculator->totalCostForSale($sale);
        if ($cogsAmount > 0) {
            $cogsAccount = $this->posting->resolveAccount($orgId, $codes['cogs'] ?? '5000');
            $inventoryAccount = $this->posting->resolveAccount($orgId, $codes['inventory'] ?? '1300');
            if ($cogsAccount && $inventoryAccount) {
                $lines[] = [
                    'account_id' => $cogsAccount->id,
                    'debit' => $cogsAmount,
                    'credit' => 0,
                    'line_notes' => 'Cost of goods sold',
                ];
                $lines[] = [
                    'account_id' => $inventoryAccount->id,
                    'debit' => 0,
                    'credit' => $cogsAmount,
                    'line_notes' => 'Inventory relief',
                ];
                $debitTotal += $cogsAmount;
            }
        }

        $debits = round(collect($lines)->sum(fn ($line) => (float) ($line['debit'] ?? 0)), 2);
        $credits = round(collect($lines)->sum(fn ($line) => (float) ($line['credit'] ?? 0)), 2);
        if ($debits !== $credits) {
            $diff = round($credits - $debits, 2);
            if (abs($diff) <= 0.02 && $lines !== []) {
                $lines[0]['debit'] = round((float) $lines[0]['debit'] + $diff, 2);
            } else {
                return null;
            }
        }

        return $lines;
    }

    /** @param  array<string, string>  $codes */
    protected function accountCodeForPaymentMethod(string $methodCode, array $codes): string
    {
        $map = config('erp.module_settings_defaults.accounting.payment_method_accounts', []);

        return $map[$methodCode]
            ?? match ($methodCode) {
                'CASH' => $codes['cash'] ?? '1000',
                'MPESA', 'CARD', 'BANK', 'TRANSFER' => $codes['bank'] ?? '1100',
                'VOUCHER', 'POINTS' => $codes['cash'] ?? '1000',
                default => $codes['cash'] ?? '1000',
            };
    }
}
