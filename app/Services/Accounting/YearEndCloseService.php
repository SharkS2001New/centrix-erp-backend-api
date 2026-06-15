<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class YearEndCloseService
{
    public function __construct(
        protected JournalPostingService $posting,
        protected FiscalPeriodService $fiscalPeriods,
    ) {}

    /** @return array{entry: JournalEntry, net_income: float, revenue_total: float, expense_total: float} */
    public function closeYear(int $orgId, User $user, int $year): array
    {
        $startDate = sprintf('%04d-01-01', $year);
        $endDate = sprintf('%04d-12-31', $year);

        $entryNumber = 'YEAR-CLOSE-'.$year;
        if (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $entryNumber)->exists()) {
            throw ValidationException::withMessages([
                'year' => ["Year {$year} has already been closed."],
            ]);
        }

        $this->fiscalPeriods->assertDateIsOpen($orgId, $endDate);

        $codes = $this->posting->defaultAccountCodes();
        $retainedAccount = $this->posting->resolveAccount($orgId, $codes['retained_earnings'] ?? '3100');
        if (! $retainedAccount) {
            throw ValidationException::withMessages([
                'accounts' => ['Retained earnings account (3100) is required for year-end close.'],
            ]);
        }

        $balances = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->join('chart_of_accounts as coa', 'coa.id', '=', 'jel.account_id')
            ->where('je.organization_id', $orgId)
            ->where('je.status', 'posted')
            ->whereBetween('je.entry_date', [$startDate, $endDate])
            ->whereIn('coa.account_type', ['revenue', 'expense'])
            ->groupBy('jel.account_id', 'coa.account_type')
            ->selectRaw('jel.account_id, coa.account_type, SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->get();

        $lines = [];
        $revenueTotal = 0.0;
        $expenseTotal = 0.0;

        foreach ($balances as $row) {
            $debit = (float) $row->total_debit;
            $credit = (float) $row->total_credit;

            if ($row->account_type === 'revenue') {
                $balance = round($credit - $debit, 2);
                if (abs($balance) < 0.005) {
                    continue;
                }
                $revenueTotal += $balance;
                $lines[] = [
                    'account_id' => (int) $row->account_id,
                    'debit' => $balance > 0 ? $balance : 0,
                    'credit' => $balance < 0 ? abs($balance) : 0,
                    'line_notes' => 'Close revenue to retained earnings',
                ];
            } else {
                $balance = round($debit - $credit, 2);
                if (abs($balance) < 0.005) {
                    continue;
                }
                $expenseTotal += $balance;
                $lines[] = [
                    'account_id' => (int) $row->account_id,
                    'debit' => 0,
                    'credit' => $balance,
                    'line_notes' => 'Close expense to retained earnings',
                ];
            }
        }

        $netIncome = round($revenueTotal - $expenseTotal, 2);
        if ($lines === [] || abs($netIncome) < 0.005) {
            throw ValidationException::withMessages([
                'year' => ["No revenue or expense activity found for {$year}."],
            ]);
        }

        $lines[] = [
            'account_id' => $retainedAccount->id,
            'debit' => $netIncome < 0 ? abs($netIncome) : 0,
            'credit' => $netIncome > 0 ? $netIncome : 0,
            'line_notes' => 'Net income for '.$year,
        ];

        $entry = $this->posting->createPosted(
            orgId: $orgId,
            user: $user,
            entryNumber: $entryNumber,
            entryDate: $endDate,
            lines: $lines,
            description: 'Year-end close '.$year,
            branchId: null,
            referenceType: 'year_end_close',
            referenceId: $year,
        );

        return [
            'entry' => $entry,
            'net_income' => $netIncome,
            'revenue_total' => round($revenueTotal, 2),
            'expense_total' => round($expenseTotal, 2),
        ];
    }
}
