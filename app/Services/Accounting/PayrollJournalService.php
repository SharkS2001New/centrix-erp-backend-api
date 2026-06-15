<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class PayrollJournalService
{
    public function __construct(
        protected AutoJournalHelper $helper,
        protected JournalPostingService $posting,
    ) {}

    public function postIfEnabled(PayrollRun $run, User $user, CapabilityGate $gate): JournalEntry|AccountingExportQueue|null
    {
        if (! $this->helper->settingEnabled($gate, 'auto_post_payroll', true)) {
            return null;
        }

        $run->loadMissing('lines');
        if ($run->lines->isEmpty()) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $codes = $this->posting->defaultAccountCodes();
        $payrollAccount = $this->posting->resolveAccount($orgId, $codes['payroll_expense'] ?? '5200');
        $apAccount = $this->posting->resolveAccount($orgId, $codes['ap'] ?? '2000');
        $bankAccount = $this->posting->resolveAccount($orgId, $codes['bank'] ?? '1100');

        if (! $payrollAccount || ! $apAccount || ! $bankAccount) {
            return null;
        }

        $grossTotal = round((float) $run->lines->sum('gross_pay'), 2);
        $employerTotal = round(
            (float) $run->lines->sum('employer_nssf') + (float) $run->lines->sum('employer_housing'),
            2,
        );
        $expenseTotal = round($grossTotal + $employerTotal, 2);

        $netTotal = round((float) $run->lines->sum('net_pay'), 2);
        $liabilityTotal = round(
            (float) $run->lines->sum('paye')
            + (float) $run->lines->sum('nssf')
            + (float) $run->lines->sum('shif')
            + (float) $run->lines->sum('housing_levy')
            + (float) $run->lines->sum('other_deductions')
            + (float) $run->lines->sum('employer_nssf')
            + (float) $run->lines->sum('employer_housing'),
            2,
        );

        if ($expenseTotal <= 0) {
            return null;
        }

        $creditTotal = round($netTotal + $liabilityTotal, 2);
        if (abs($creditTotal - $expenseTotal) > 0.02) {
            $liabilityTotal = round($expenseTotal - $netTotal, 2);
        }

        $lines = [
            [
                'account_id' => $payrollAccount->id,
                'debit' => $expenseTotal,
                'credit' => 0,
                'line_notes' => 'Payroll expense',
            ],
            [
                'account_id' => $bankAccount->id,
                'debit' => 0,
                'credit' => $netTotal,
                'line_notes' => 'Net pay',
            ],
            [
                'account_id' => $apAccount->id,
                'debit' => 0,
                'credit' => $liabilityTotal,
                'line_notes' => 'Payroll liabilities',
            ],
        ];

        return $this->helper->postOrQueue(
            gate: $gate,
            user: $user,
            orgId: $orgId,
            entryNumber: 'PAYROLL-'.$run->id,
            entryDate: ($run->run_date ?? now())->toDateString(),
            lines: $lines,
            description: 'Auto journal for payroll run #'.$run->id,
            branchId: null,
            referenceType: 'payroll_run',
            referenceId: (int) $run->id,
        );
    }
}
