<?php

namespace App\Services\Accounting;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    /** @param  array<string, mixed>  $filters */
    public function generalLedger(int $orgId, array $filters): LengthAwarePaginator
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->select([
                'je.id as journal_entry_id',
                'je.entry_date',
                'je.entry_number',
                'je.description as entry_description',
                'je.reference_type',
                'je.branch_id',
                'coa.id as account_id',
                'coa.account_code',
                'coa.account_name',
                'coa.account_type',
                'jel.debit',
                'jel.credit',
                'jel.line_notes',
            ])
            ->orderByDesc('je.entry_date')
            ->orderByDesc('je.id');

        if (! empty($filters['branch_id'])) {
            $query->where('je.branch_id', $filters['branch_id']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('coa.id', $filters['account_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->where('je.entry_date', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->where('je.entry_date', '<=', $filters['to_date']);
        }

        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return $query->paginate($perPage);
    }

    /** @param  array<string, mixed>  $filters
     * @return array{rows: Collection<int, object>, summary: array<string, float>}
     */
    public function trialBalance(int $orgId, array $filters): array
    {
        $toDate = $filters['to_date'] ?? now()->toDateString();
        $fromDate = $filters['from_date'] ?? null;

        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->orderBy('account_code')
            ->get();

        $movementQuery = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->where('je.entry_date', '<=', $toDate);

        if (! empty($filters['branch_id'])) {
            $movementQuery->where('je.branch_id', $filters['branch_id']);
        }

        $movements = $movementQuery
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $periodMovements = null;
        if ($fromDate) {
            $periodQuery = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
                ->where('je.organization_id', $orgId)
                ->where('je.status', 'posted')
                ->whereBetween('je.entry_date', [$fromDate, $toDate]);

            if (! empty($filters['branch_id'])) {
                $periodQuery->where('je.branch_id', $filters['branch_id']);
            }

            $periodMovements = $periodQuery
                ->groupBy('jel.account_id')
                ->selectRaw('jel.account_id, SUM(jel.debit) as period_debit, SUM(jel.credit) as period_credit')
                ->get()
                ->keyBy('account_id');
        }

        $rows = collect();
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $raw = $movements->get($account->id);
            $debit = (float) ($raw->total_debit ?? 0);
            $credit = (float) ($raw->total_credit ?? 0);
            $balance = $this->signedBalance($account->account_type, $debit, $credit);

            if (abs($balance) < 0.005 && ! $periodMovements) {
                continue;
            }

            $period = $periodMovements?->get($account->id);
            $periodDebit = (float) ($period->period_debit ?? 0);
            $periodCredit = (float) ($period->period_credit ?? 0);

            if ($periodMovements && $periodDebit <= 0 && $periodCredit <= 0 && abs($balance) < 0.005) {
                continue;
            }

            $debitColumn = 0.0;
            $creditColumn = 0.0;
            if (in_array($account->account_type, ['asset', 'expense'], true)) {
                $debitColumn = max(0, round($balance, 2));
                $creditColumn = max(0, round(-$balance, 2));
            } else {
                $creditColumn = max(0, round($balance, 2));
                $debitColumn = max(0, round(-$balance, 2));
            }

            $totalDebit += $debitColumn;
            $totalCredit += $creditColumn;

            $rows->push((object) [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
                'period_debit' => round($periodDebit, 2),
                'period_credit' => round($periodCredit, 2),
                'debit_balance' => $debitColumn,
                'credit_balance' => $creditColumn,
            ]);
        }

        return [
            'rows' => $rows->values(),
            'summary' => [
                'total_debit' => round($totalDebit, 2),
                'total_credit' => round($totalCredit, 2),
            ],
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function balanceSheet(int $orgId, array $filters): array
    {
        $trial = $this->trialBalance($orgId, $filters);
        $rows = collect($trial['rows']);

        $sections = [
            'asset' => ['label' => 'Assets', 'rows' => collect(), 'total' => 0.0],
            'liability' => ['label' => 'Liabilities', 'rows' => collect(), 'total' => 0.0],
            'equity' => ['label' => 'Equity', 'rows' => collect(), 'total' => 0.0],
        ];

        foreach ($rows as $row) {
            $type = $row->account_type;
            if (! isset($sections[$type])) {
                continue;
            }
            $amount = (float) $row->debit_balance - (float) $row->credit_balance;
            if ($type !== 'asset') {
                $amount = (float) $row->credit_balance - (float) $row->debit_balance;
            }
            if (abs($amount) < 0.005) {
                continue;
            }
            $sections[$type]['rows']->push((object) [
                'account_code' => $row->account_code,
                'account_name' => $row->account_name,
                'amount' => round($amount, 2),
            ]);
            $sections[$type]['total'] += $amount;
        }

        $totalAssets = round($sections['asset']['total'], 2);
        $totalLiabilities = round($sections['liability']['total'], 2);
        $totalEquity = round($sections['equity']['total'], 2);

        $flatRows = collect();
        foreach ($sections as $key => $section) {
            if ($section['rows']->isEmpty()) {
                continue;
            }
            $flatRows->push((object) [
                'section' => $section['label'],
                'account_code' => '',
                'account_name' => '',
                'amount' => null,
                'is_header' => true,
            ]);
            foreach ($section['rows'] as $line) {
                $flatRows->push((object) [
                    'section' => $section['label'],
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'amount' => $line->amount,
                    'is_header' => false,
                ]);
            }
            $flatRows->push((object) [
                'section' => $section['label'],
                'account_code' => '',
                'account_name' => 'Total ' . $section['label'],
                'amount' => round($section['total'], 2),
                'is_total' => true,
            ]);
        }

        return [
            'rows' => $flatRows->values(),
            'summary' => [
                'total_assets' => $totalAssets,
                'total_liabilities' => $totalLiabilities,
                'total_equity' => $totalEquity,
                'liabilities_and_equity' => round($totalLiabilities + $totalEquity, 2),
            ],
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function profitAndLoss(int $orgId, array $filters): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate = $filters['to_date'] ?? now()->toDateString();

        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->whereIn('account_type', ['revenue', 'expense'])
            ->where('is_active', true)
            ->orderBy('account_type')
            ->orderBy('account_code')
            ->get();

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$fromDate, $toDate]);

        if (! empty($filters['branch_id'])) {
            $query->where('je.branch_id', $filters['branch_id']);
        }

        $movements = $query
            ->groupBy('jel.account_id')
            ->selectRaw('jel.account_id, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->get()
            ->keyBy('account_id');

        $rows = collect();
        $totalRevenue = 0.0;
        $totalExpenses = 0.0;

        foreach ($accounts as $account) {
            $raw = $movements->get($account->id);
            $debit = (float) ($raw->total_debit ?? 0);
            $credit = (float) ($raw->total_credit ?? 0);
            $amount = $account->account_type === 'revenue'
                ? round($credit - $debit, 2)
                : round($debit - $credit, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            if ($account->account_type === 'revenue') {
                $totalRevenue += $amount;
            } else {
                $totalExpenses += $amount;
            }

            $rows->push((object) [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'account_type' => $account->account_type,
                'amount' => $amount,
            ]);
        }

        $netIncome = round($totalRevenue - $totalExpenses, 2);

        return [
            'rows' => $rows->values(),
            'summary' => [
                'total_revenue' => round($totalRevenue, 2),
                'total_expenses' => round($totalExpenses, 2),
                'net_income' => $netIncome,
            ],
        ];
    }

    /** @param  array<string, mixed>  $filters */
    public function cashFlow(int $orgId, array $filters): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfMonth()->toDateString();
        $toDate = $filters['to_date'] ?? now()->toDateString();

        $cashAccounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('account_code', 'like', '10%')
                    ->orWhere('account_code', 'like', '11%')
                    ->orWhere('account_name', 'like', '%cash%')
                    ->orWhere('account_name', 'like', '%bank%');
            })
            ->pluck('id');

        if ($cashAccounts->isEmpty()) {
            $cashAccounts = DB::table('chart_of_accounts')
                ->where('organization_id', $orgId)
                ->where('account_type', 'asset')
                ->where('account_code', '1000')
                ->pluck('id');
        }

        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->whereIn('jel.account_id', $cashAccounts)
            ->whereBetween('je.entry_date', [$fromDate, $toDate])
            ->select([
                'je.entry_date',
                'je.entry_number',
                'coa.account_code',
                'coa.account_name',
                'je.description as entry_description',
                'je.reference_type',
                'jel.debit as cash_in',
                'jel.credit as cash_out',
            ])
            ->orderBy('je.entry_date')
            ->orderBy('je.entry_number');

        if (! empty($filters['branch_id'])) {
            $query->where('je.branch_id', $filters['branch_id']);
        }

        $rows = $query->get()->map(function ($row) {
            $in = (float) $row->cash_in;
            $out = (float) $row->cash_out;
            $row->net_cash_change = round($in - $out, 2);

            return $row;
        });

        return [
            'rows' => $rows->values(),
            'summary' => [
                'cash_in' => round($rows->sum(fn ($r) => (float) $r->cash_in), 2),
                'cash_out' => round($rows->sum(fn ($r) => (float) $r->cash_out), 2),
                'net_change' => round($rows->sum(fn ($r) => (float) $r->net_cash_change), 2),
            ],
        ];
    }

    protected function signedBalance(string $accountType, float $debit, float $credit): float
    {
        if (in_array($accountType, ['asset', 'expense'], true)) {
            return round($debit - $credit, 2);
        }

        return round($credit - $debit, 2);
    }
}
