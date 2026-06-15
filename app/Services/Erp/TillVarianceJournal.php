<?php

namespace App\Services\Erp;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Accounting\AccountingSettingsResolver;
use App\Services\Accounting\JournalExportService;
use App\Services\Accounting\JournalPostingService;

class TillVarianceJournal
{
    public function __construct(
        protected JournalPostingService $posting,
        protected JournalExportService $exports,
        protected AccountingSettingsResolver $settings,
    ) {}

    public function postIfEnabled(TillFloatSession $session, User $user, ?float $variance): JournalEntry|AccountingExportQueue|null
    {
        if ($variance === null || abs($variance) < 0.01) {
            return null;
        }

        $gate = app(ErpContext::class)->gateForUser($user);
        if (! $gate->enabled('accounting')) {
            return null;
        }

        $settings = $gate->moduleSettings('accounting') ?? [];
        if (! ($settings['post_till_variance'] ?? true)) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $codes = $this->posting->defaultAccountCodes();
        $cash = $this->posting->resolveAccount($orgId, $codes['cash'] ?? '1000');
        $varianceAccount = $this->posting->resolveAccount($orgId, $codes['till_variance'] ?? '5100');

        if (! $cash || ! $varianceAccount) {
            return null;
        }

        $entryNumber = 'TILL-VAR-'.$session->id;
        $amount = round(abs($variance), 2);
        $isShort = $variance < 0;

        $lines = $isShort
            ? [
                ['account_id' => $varianceAccount->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $cash->id, 'debit' => 0, 'credit' => $amount],
            ]
            : [
                ['account_id' => $cash->id, 'debit' => $amount, 'credit' => 0],
                ['account_id' => $varianceAccount->id, 'debit' => 0, 'credit' => $amount],
            ];

        $financeSettings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if ($financeSettings->usesExternalLedger()) {
            return $this->exports->queueTillVariance($session, $user, $gate, $lines, $variance);
        }

        return $this->posting->createPosted(
            orgId: $orgId,
            user: $user,
            entryNumber: $entryNumber,
            entryDate: now()->toDateString(),
            lines: $lines,
            description: sprintf(
                'Till session #%d %s variance (%s)',
                $session->id,
                $isShort ? 'cash short' : 'cash over',
                number_format($variance, 2),
            ),
            branchId: $session->branch_id,
            referenceType: 'till_float_session',
            referenceId: $session->id,
        );
    }
}
