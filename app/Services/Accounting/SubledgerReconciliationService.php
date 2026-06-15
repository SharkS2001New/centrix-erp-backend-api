<?php

namespace App\Services\Accounting;

use Illuminate\Support\Facades\DB;

class SubledgerReconciliationService
{
    public function __construct(
        protected JournalPostingService $posting,
    ) {}

    /** @param  array<string, mixed>  $filters */
    public function summarize(int $orgId, array $filters = []): array
    {
        $asOf = $filters['to_date'] ?? now()->toDateString();
        $codes = $this->posting->defaultAccountCodes();

        return [
            'as_of' => $asOf,
            'ar' => $this->reconcileAr($orgId, $codes['ar'] ?? '1200', $asOf),
            'ap' => $this->reconcileAp($orgId, $codes['ap'] ?? '2000', $asOf),
        ];
    }

    protected function reconcileAr(int $orgId, string $accountCode, string $asOf): array
    {
        $glBalance = $this->glBalanceForAccountCode($orgId, $accountCode, $asOf);
        $subledgerTotal = (float) DB::table('v_accounts_receivable_summary')
            ->sum('total_outstanding');

        return $this->buildRow('Accounts Receivable', $accountCode, $glBalance, $subledgerTotal);
    }

    protected function reconcileAp(int $orgId, string $accountCode, string $asOf): array
    {
        $glBalance = $this->glBalanceForAccountCode($orgId, $accountCode, $asOf);
        $subledgerTotal = (float) DB::table('v_supplier_payables')
            ->sum('balance_due');

        return $this->buildRow('Accounts Payable', $accountCode, $glBalance, $subledgerTotal);
    }

    protected function glBalanceForAccountCode(int $orgId, string $accountCode, string $asOf): float
    {
        $account = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('account_code', $accountCode)
            ->first();

        if (! $account) {
            return 0.0;
        }

        $raw = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $account->id)
            ->where('je.entry_date', '<=', $asOf)
            ->selectRaw('SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->first();

        $debit = (float) ($raw->total_debit ?? 0);
        $credit = (float) ($raw->total_credit ?? 0);

        if (in_array($account->account_type, ['asset', 'expense'], true)) {
            return round($debit - $credit, 2);
        }

        return round($credit - $debit, 2);
    }

    protected function buildRow(string $label, string $accountCode, float $glBalance, float $subledgerTotal): array
    {
        $variance = round($glBalance - $subledgerTotal, 2);

        return [
            'label' => $label,
            'control_account_code' => $accountCode,
            'gl_balance' => $glBalance,
            'subledger_total' => round($subledgerTotal, 2),
            'variance' => $variance,
            'reconciled' => abs($variance) < 0.02,
        ];
    }
}
