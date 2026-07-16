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
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $escaped = \App\Support\SqlLikeSearch::escape($term);
            $prefix = $escaped.'%';
            $contains = '%'.$escaped.'%';
            $query->where(function ($sub) use ($prefix, $contains) {
                $sub->where('je.entry_number', 'like', $prefix)
                    ->orWhere('coa.account_code', 'like', $prefix)
                    ->orWhere('je.description', 'like', $contains)
                    ->orWhere('je.reference_type', 'like', $contains)
                    ->orWhere('coa.account_name', 'like', $contains)
                    ->orWhere('jel.line_notes', 'like', $contains);
            });
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
            ->orderByRaw("CASE WHEN account_type = 'revenue' THEN 0 ELSE 1 END")
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
            ->keyBy(fn ($row) => (int) $row->account_id);

        $sections = [
            'revenue' => ['label' => 'Revenue', 'rows' => collect(), 'total' => 0.0],
            'expense' => ['label' => 'Expenses', 'rows' => collect(), 'total' => 0.0],
        ];

        foreach ($accounts as $account) {
            $raw = $movements->get((int) $account->id);
            $debit = (float) ($raw->total_debit ?? 0);
            $credit = (float) ($raw->total_credit ?? 0);
            $type = (string) $account->account_type;
            $amount = $type === 'revenue'
                ? round($credit - $debit, 2)
                : round($debit - $credit, 2);

            if (abs($amount) < 0.005 || ! isset($sections[$type])) {
                continue;
            }

            $sections[$type]['rows']->push((object) [
                'account_code' => $account->account_code,
                'account_name' => $account->account_name,
                'amount' => $amount,
            ]);
            $sections[$type]['total'] += $amount;
        }

        $totalRevenue = round($sections['revenue']['total'], 2);
        $totalExpenses = round($sections['expense']['total'], 2);
        $netIncome = round($totalRevenue - $totalExpenses, 2);

        $flatRows = collect();
        foreach ($sections as $section) {
            if ($section['rows']->isEmpty()) {
                continue;
            }
            $flatRows->push((object) [
                'section' => $section['label'],
                'account_code' => '',
                'account_name' => '',
                'account_type' => null,
                'amount' => null,
                'is_header' => true,
            ]);
            foreach ($section['rows'] as $line) {
                $flatRows->push((object) [
                    'section' => $section['label'],
                    'account_code' => $line->account_code,
                    'account_name' => $line->account_name,
                    'account_type' => $section['label'] === 'Revenue' ? 'revenue' : 'expense',
                    'amount' => $line->amount,
                    'is_header' => false,
                ]);
            }
            $flatRows->push((object) [
                'section' => $section['label'],
                'account_code' => '',
                'account_name' => 'Total '.$section['label'],
                'account_type' => null,
                'amount' => round($section['total'], 2),
                'is_total' => true,
            ]);
        }

        $flatRows->push((object) [
            'section' => 'Net income',
            'account_code' => '',
            'account_name' => 'Net income',
            'account_type' => null,
            'amount' => $netIncome,
            'is_total' => true,
        ]);

        return [
            'rows' => $flatRows->values()->all(),
            'summary' => [
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
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

    /**
     * GAAP indirect-method cash flow statement.
     *
     * @param  array<string, mixed>  $filters
     * @return array{
     *     method: string,
     *     sections: array<int, array<string, mixed>>,
     *     data: \Illuminate\Support\Collection<int, object>,
     *     summary: array<string, float>
     * }
     */
    public function gaapCashFlow(int $orgId, array $filters): array
    {
        $fromDate = $filters['from_date'] ?? now()->startOfYear()->toDateString();
        $toDate = $filters['to_date'] ?? now()->toDateString();
        $branchId = ! empty($filters['branch_id']) ? (int) $filters['branch_id'] : null;
        $dayBefore = \Carbon\Carbon::parse($fromDate)->subDay()->toDateString();

        $pl = $this->profitAndLoss($orgId, array_merge($filters, [
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]));
        $netIncome = (float) ($pl['summary']['net_income'] ?? 0);

        $operatingLines = [
            ['label' => 'Net income', 'amount' => round($netIncome, 2)],
        ];

        $workingCapital = [
            ['label' => 'Accounts receivable', 'code' => '1200', 'type' => 'asset'],
            ['label' => 'Inventory', 'code' => '1300', 'type' => 'asset'],
            ['label' => 'Accounts payable', 'code' => '2000', 'type' => 'liability'],
            ['label' => 'VAT payable', 'code' => '2100', 'type' => 'liability'],
        ];

        foreach ($workingCapital as $wc) {
            $start = $this->accountBalanceAsOf($orgId, $wc['code'], $dayBefore, $branchId);
            $end = $this->accountBalanceAsOf($orgId, $wc['code'], $toDate, $branchId);
            $change = round($end - $start, 2);
            if (abs($change) < 0.005) {
                continue;
            }

            $cashEffect = $wc['type'] === 'asset' ? -$change : $change;
            $direction = $change > 0 ? 'Increase in' : 'Decrease in';
            $operatingLines[] = [
                'label' => "{$direction} {$wc['label']}",
                'amount' => round($cashEffect, 2),
            ];
        }

        $netOperating = round(collect($operatingLines)->sum('amount'), 2);

        $investingLines = $this->investingCashFlows($orgId, $fromDate, $toDate, $branchId);
        $netInvesting = round(collect($investingLines)->sum('amount'), 2);

        $financingLines = $this->financingCashFlows($orgId, $dayBefore, $toDate, $branchId, $netIncome);
        $netFinancing = round(collect($financingLines)->sum('amount'), 2);

        $netChange = round($netOperating + $netInvesting + $netFinancing, 2);
        $beginningCash = $this->cashBalanceAsOf($orgId, $dayBefore, $branchId);
        $endingCash = $this->cashBalanceAsOf($orgId, $toDate, $branchId);

        $sections = [
            [
                'key' => 'operating',
                'label' => 'Cash flows from operating activities',
                'lines' => $operatingLines,
                'subtotal' => $netOperating,
            ],
            [
                'key' => 'investing',
                'label' => 'Cash flows from investing activities',
                'lines' => $investingLines,
                'subtotal' => $netInvesting,
            ],
            [
                'key' => 'financing',
                'label' => 'Cash flows from financing activities',
                'lines' => $financingLines,
                'subtotal' => $netFinancing,
            ],
        ];

        $flatRows = collect();
        foreach ($sections as $section) {
            $flatRows->push((object) [
                'section' => $section['label'],
                'line_label' => '',
                'amount' => null,
                'is_header' => true,
            ]);
            foreach ($section['lines'] as $line) {
                $flatRows->push((object) [
                    'section' => $section['label'],
                    'line_label' => $line['label'],
                    'amount' => $line['amount'],
                    'is_header' => false,
                ]);
            }
            $flatRows->push((object) [
                'section' => $section['label'],
                'line_label' => 'Net cash from '.strtolower(str_replace('Cash flows from ', '', $section['label'])),
                'amount' => $section['subtotal'],
                'is_total' => true,
            ]);
        }

        $flatRows->push((object) [
            'section' => 'Summary',
            'line_label' => 'Net increase (decrease) in cash',
            'amount' => $netChange,
            'is_total' => true,
        ]);
        $flatRows->push((object) [
            'section' => 'Summary',
            'line_label' => 'Cash at beginning of period',
            'amount' => $beginningCash,
            'is_header' => false,
        ]);
        $flatRows->push((object) [
            'section' => 'Summary',
            'line_label' => 'Cash at end of period',
            'amount' => $endingCash,
            'is_total' => true,
        ]);

        return [
            'method' => 'indirect',
            'sections' => $sections,
            'data' => $flatRows->values(),
            'summary' => [
                'net_operating' => $netOperating,
                'net_investing' => $netInvesting,
                'net_financing' => $netFinancing,
                'net_change_in_cash' => $netChange,
                'beginning_cash' => round($beginningCash, 2),
                'ending_cash' => round($endingCash, 2),
            ],
        ];
    }

    /** @return list<array{label: string, amount: float}> */
    protected function investingCashFlows(int $orgId, string $fromDate, string $toDate, ?int $branchId): array
    {
        $lines = [];
        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('is_active', true)
            ->where('account_type', 'asset')
            ->where(function ($q) {
                $q->where('account_code', 'like', '14%')
                    ->orWhere('account_code', 'like', '15%')
                    ->orWhere('account_code', 'like', '16%')
                    ->orWhere('account_code', 'like', '17%')
                    ->orWhere('account_code', 'like', '18%')
                    ->orWhere('account_code', 'like', '19%');
            })
            ->whereNotIn('account_code', ['1300'])
            ->get();

        foreach ($accounts as $account) {
            $netDebit = $this->periodNetDebit($orgId, (int) $account->id, $fromDate, $toDate, $branchId);
            if (abs($netDebit) < 0.005) {
                continue;
            }
            $lines[] = [
                'label' => 'Purchase of '.$account->account_name,
                'amount' => round(-$netDebit, 2),
            ];
        }

        if ($lines === []) {
            $lines[] = ['label' => 'No investing activity', 'amount' => 0.0];
        }

        return $lines;
    }

    /** @return list<array{label: string, amount: float}> */
    protected function financingCashFlows(int $orgId, string $dayBefore, string $toDate, ?int $branchId, float $netIncome): array
    {
        $lines = [];
        $ownerEquityChange = $this->balanceChange($orgId, '3000', $dayBefore, $toDate, $branchId, 'equity');
        if (abs($ownerEquityChange) >= 0.005) {
            $lines[] = [
                'label' => $ownerEquityChange >= 0 ? 'Owner contributions' : 'Owner draws',
                'amount' => round($ownerEquityChange, 2),
            ];
        }

        $retainedChange = $this->balanceChange($orgId, '3100', $dayBefore, $toDate, $branchId, 'equity');
        $retainedFromClose = round($retainedChange - $netIncome, 2);
        if (abs($retainedFromClose) >= 0.005) {
            $lines[] = [
                'label' => $retainedFromClose >= 0 ? 'Other equity / retained earnings adjustments' : 'Distributions from retained earnings',
                'amount' => round($retainedFromClose, 2),
            ];
        }

        if ($lines === []) {
            $lines[] = ['label' => 'No financing activity', 'amount' => 0.0];
        }

        return $lines;
    }

    protected function accountBalanceAsOf(int $orgId, string $accountCode, string $asOfDate, ?int $branchId): float
    {
        $account = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->where('account_code', $accountCode)
            ->where('is_active', true)
            ->first();

        if (! $account) {
            return 0.0;
        }

        return $this->signedAccountBalance($orgId, (int) $account->id, (string) $account->account_type, $asOfDate, $branchId);
    }

    protected function cashBalanceAsOf(int $orgId, string $asOfDate, ?int $branchId): float
    {
        $codes = config('erp.module_settings_defaults.accounting.account_codes', []);
        $cashCodes = array_unique([
            $codes['cash'] ?? '1000',
            $codes['bank'] ?? '1100',
        ]);

        $total = 0.0;
        foreach ($cashCodes as $code) {
            $total += $this->accountBalanceAsOf($orgId, $code, $asOfDate, $branchId);
        }

        return round($total, 2);
    }

    protected function balanceChange(
        int $orgId,
        string $accountCode,
        string $dayBefore,
        string $toDate,
        ?int $branchId,
        string $accountType,
    ): float {
        $start = $this->accountBalanceAsOf($orgId, $accountCode, $dayBefore, $branchId);
        $end = $this->accountBalanceAsOf($orgId, $accountCode, $toDate, $branchId);

        return round($end - $start, 2);
    }

    protected function signedAccountBalance(
        int $orgId,
        int $accountId,
        string $accountType,
        string $asOfDate,
        ?int $branchId,
    ): float {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->where('je.entry_date', '<=', $asOfDate);

        if ($branchId) {
            $query->where('je.branch_id', $branchId);
        }

        $totals = $query
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as total_debit, COALESCE(SUM(jel.credit), 0) as total_credit')
            ->first();

        $debit = (float) ($totals->total_debit ?? 0);
        $credit = (float) ($totals->total_credit ?? 0);

        return $this->signedBalance($accountType, $debit, $credit);
    }

    protected function periodNetDebit(int $orgId, int $accountId, string $fromDate, string $toDate, ?int $branchId): float
    {
        $query = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->whereBetween('je.entry_date', [$fromDate, $toDate]);

        if ($branchId) {
            $query->where('je.branch_id', $branchId);
        }

        $totals = $query
            ->selectRaw('COALESCE(SUM(jel.debit), 0) as total_debit, COALESCE(SUM(jel.credit), 0) as total_credit')
            ->first();

        return round((float) ($totals->total_debit ?? 0) - (float) ($totals->total_credit ?? 0), 2);
    }

    protected function signedBalance(string $accountType, float $debit, float $credit): float
    {
        if (in_array($accountType, ['asset', 'expense'], true)) {
            return round($debit - $credit, 2);
        }

        return round($credit - $debit, 2);
    }
}
