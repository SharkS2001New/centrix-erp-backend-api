<?php

namespace App\Services\Accounting;

use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatementLine;
use App\Models\ChartOfAccount;
use App\Models\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BankReconciliationService
{
    public function __construct(
        protected JournalPostingService $posting,
    ) {}

    /** @return Collection<int, ChartOfAccount> */
    public function listBankAccounts(int $organizationId): Collection
    {
        $bankCode = (string) ($this->posting->defaultAccountCodes()['bank'] ?? '1100');
        $cashCode = (string) ($this->posting->defaultAccountCodes()['cash'] ?? '1000');

        return ChartOfAccount::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->where('account_type', 'asset')
            ->where(function ($query) use ($bankCode, $cashCode) {
                $query->where('account_code', $bankCode)
                    ->orWhere('account_code', 'like', '11%')
                    ->orWhere('account_code', $cashCode)
                    ->orWhereRaw('LOWER(account_name) LIKE ?', ['%bank%'])
                    ->orWhereRaw('LOWER(account_name) LIKE ?', ['%mpesa%']);
            })
            ->orderBy('account_code')
            ->get();
    }

    public function glBalanceForAccount(int $organizationId, int $accountId, string $asOf): float
    {
        $account = ChartOfAccount::query()
            ->where('organization_id', $organizationId)
            ->where('id', $accountId)
            ->first();

        if (! $account) {
            return 0.0;
        }

        $raw = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $organizationId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->where('je.entry_date', '<=', $asOf)
            ->selectRaw('SUM(jel.debit) as total_debit, SUM(jel.credit) as total_credit')
            ->first();

        $debit = (float) ($raw->total_debit ?? 0);
        $credit = (float) ($raw->total_credit ?? 0);

        return round($debit - $credit, 2);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function create(User $user, array $data, array $lines = []): BankReconciliation
    {
        $orgId = (int) $user->organization_id;
        $account = $this->findBankAccount($orgId, (int) $data['chart_of_account_id']);
        $periodEnd = (string) $data['period_end'];
        $periodStart = (string) ($data['period_start'] ?? $periodEnd);

        if ($periodStart > $periodEnd) {
            throw new InvalidArgumentException('Period start must be on or before period end.');
        }

        return DB::transaction(function () use ($user, $orgId, $account, $data, $lines, $periodStart, $periodEnd) {
            $reconciliation = BankReconciliation::query()->create([
                'organization_id' => $orgId,
                'chart_of_account_id' => $account->id,
                'title' => $data['title'] ?? null,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'opening_balance' => $data['opening_balance'] ?? null,
                'statement_balance' => (float) $data['statement_balance'],
                'book_balance' => $this->glBalanceForAccount($orgId, (int) $account->id, $periodEnd),
                'status' => 'in_progress',
                'notes' => $data['notes'] ?? null,
                'imported_filename' => $data['imported_filename'] ?? null,
                'created_by' => (int) $user->id,
            ]);

            $this->importStatementLines($reconciliation, $lines);

            return $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function parseCsvRows(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        $lines = preg_split('/\r\n|\r|\n/', $csv) ?: [];
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));
        if ($lines === []) {
            return [];
        }

        $delimiter = str_contains((string) $lines[0], ';') ? ';' : ',';
        $header = str_getcsv((string) $lines[0], $delimiter);
        $header = array_map(fn ($col) => strtolower(trim((string) $col)), $header);
        $hasHeader = $this->looksLikeHeader($header);
        $start = $hasHeader ? 1 : 0;

        $parsed = [];
        for ($i = $start; $i < count($lines); $i++) {
            $cells = str_getcsv((string) $lines[$i], $delimiter);
            $row = $this->mapCsvRow($hasHeader ? $header : null, $cells);
            if ($row !== null) {
                $parsed[] = $row;
            }
        }

        return $parsed;
    }

    public function show(int $organizationId, int $reconciliationId): array
    {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $reconciliation = $this->refreshSummary($reconciliation);

        $matchedJournalLineIds = $reconciliation->matches()
            ->pluck('journal_entry_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $bookItems = $this->bookTransactions(
            $organizationId,
            (int) $reconciliation->chart_of_account_id,
            (string) $reconciliation->period_end,
            $matchedJournalLineIds,
        );

        $suggestions = $reconciliation->status === 'in_progress'
            ? $this->buildMatchSuggestions($reconciliation, $bookItems)
            : [];

        $statementLines = $reconciliation->statementLines()
            ->orderBy('sort_order')
            ->orderBy('line_date')
            ->get()
            ->map(fn (BankStatementLine $line) => $this->serializeStatementLine($line))
            ->values()
            ->all();

        $matches = $reconciliation->matches()
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankReconciliationMatch $match) => $this->serializeMatch($match, $statementLines, $bookItems))
            ->values()
            ->all();

        return [
            'reconciliation' => $this->serializeReconciliation($reconciliation),
            'statement_lines' => $statementLines,
            'book_items' => $bookItems,
            'suggestions' => $suggestions,
            'matches' => $matches,
        ];
    }

    /** @return Collection<int, BankReconciliation> */
    public function listForOrganization(int $organizationId): Collection
    {
        return BankReconciliation::query()
            ->with('chartOfAccount:id,account_code,account_name')
            ->where('organization_id', $organizationId)
            ->orderByDesc('period_end')
            ->orderByDesc('id')
            ->get()
            ->map(function (BankReconciliation $reconciliation) {
                return $this->serializeReconciliation($this->refreshSummary($reconciliation));
            });
    }

    public function applyMatch(
        int $organizationId,
        int $reconciliationId,
        int $statementLineId,
        int $journalEntryLineId,
        User $user,
        string $matchType = 'manual',
    ): BankReconciliation {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        $statementLine = BankStatementLine::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('id', $statementLineId)
            ->firstOrFail();

        if ($statementLine->match_status === 'matched') {
            throw new InvalidArgumentException('Statement line is already matched.');
        }

        $journalLine = $this->findBookLine($organizationId, (int) $reconciliation->chart_of_account_id, $journalEntryLineId);

        if (BankReconciliationMatch::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('journal_entry_line_id', $journalLine->id)
            ->exists()) {
            throw new InvalidArgumentException('Book transaction is already matched in this reconciliation.');
        }

        $bookAmount = $this->signedBookAmount($journalLine);
        if (abs(abs($bookAmount) - abs((float) $statementLine->amount)) > 0.02) {
            throw new InvalidArgumentException('Statement amount does not match the book transaction amount.');
        }

        DB::transaction(function () use ($reconciliation, $statementLine, $journalLine, $user, $matchType, $bookAmount) {
            BankReconciliationMatch::query()->create([
                'bank_reconciliation_id' => $reconciliation->id,
                'bank_statement_line_id' => $statementLine->id,
                'journal_entry_line_id' => $journalLine->id,
                'match_type' => $matchType,
                'matched_amount' => abs($bookAmount),
                'matched_by' => (int) $user->id,
                'matched_at' => now(),
            ]);

            $statementLine->update(['match_status' => 'matched']);
        });

        return $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));
    }

    public function removeMatch(int $organizationId, int $reconciliationId, int $matchId): BankReconciliation
    {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        $match = BankReconciliationMatch::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('id', $matchId)
            ->firstOrFail();

        DB::transaction(function () use ($match) {
            if ($match->bank_statement_line_id) {
                BankStatementLine::query()
                    ->where('id', $match->bank_statement_line_id)
                    ->update(['match_status' => 'unmatched']);
            }
            $match->delete();
        });

        return $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));
    }

    /**
     * Import bank statement lines into an in-progress reconciliation.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    public function importStatement(
        int $organizationId,
        int $reconciliationId,
        array $lines = [],
        ?string $csv = null,
    ): array {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        if ($csv !== null && trim($csv) !== '') {
            $lines = array_merge($lines, $this->parseCsvRows($csv));
        }

        if ($lines === []) {
            throw new InvalidArgumentException('Provide statement lines or CSV data to import.');
        }

        $this->importStatementLines($reconciliation, $lines);
        $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));

        return $this->show($organizationId, $reconciliationId);
    }

    public function excludeStatementLine(int $organizationId, int $reconciliationId, int $statementLineId): BankReconciliation
    {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        $line = BankStatementLine::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('id', $statementLineId)
            ->firstOrFail();

        if ($line->match_status === 'matched') {
            throw new InvalidArgumentException('Matched statement lines cannot be excluded. Unmatch first.');
        }

        $line->update(['match_status' => 'excluded']);

        return $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));
    }

    public function clearBookItem(
        int $organizationId,
        int $reconciliationId,
        int $journalEntryLineId,
        User $user,
    ): BankReconciliation {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        $journalLine = $this->findBookLine(
            $organizationId,
            (int) $reconciliation->chart_of_account_id,
            $journalEntryLineId,
        );

        if (BankReconciliationMatch::query()
            ->where('bank_reconciliation_id', $reconciliation->id)
            ->where('journal_entry_line_id', $journalLine->id)
            ->exists()) {
            throw new InvalidArgumentException('Book transaction is already cleared in this reconciliation.');
        }

        $bookAmount = abs($this->signedBookAmount($journalLine));

        DB::transaction(function () use ($reconciliation, $journalLine, $user, $bookAmount) {
            BankReconciliationMatch::query()->create([
                'bank_reconciliation_id' => $reconciliation->id,
                'bank_statement_line_id' => null,
                'journal_entry_line_id' => $journalLine->id,
                'match_type' => 'manual',
                'matched_amount' => $bookAmount,
                'matched_by' => (int) $user->id,
                'matched_at' => now(),
            ]);
        });

        return $this->refreshSummary($reconciliation->fresh(['chartOfAccount']));
    }

    public function createAdjustment(
        int $organizationId,
        int $reconciliationId,
        User $user,
        ?string $description = null,
        ?int $offsetAccountId = null,
    ): array {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);
        $reconciliation = $this->refreshSummary($reconciliation);

        $variance = (float) $reconciliation->variance;
        if (abs($variance) < 0.02) {
            throw new InvalidArgumentException('There is no variance to adjust.');
        }

        $codes = $this->posting->defaultAccountCodes();
        $offsetAccount = $offsetAccountId
            ? ChartOfAccount::query()
                ->where('organization_id', $organizationId)
                ->where('id', $offsetAccountId)
                ->firstOrFail()
            : $this->posting->resolveAccount($organizationId, $codes['operating_expense'] ?? '5300');

        if (! $offsetAccount) {
            throw new InvalidArgumentException('Offset account for reconciliation adjustment is not configured.');
        }

        $bankAccountId = (int) $reconciliation->chart_of_account_id;
        $amount = abs($variance);
        $note = $description ?: 'Bank reconciliation adjustment';

        if ($variance > 0) {
            $lines = [
                ['account_id' => $bankAccountId, 'debit' => $amount, 'credit' => 0, 'line_notes' => $note],
                ['account_id' => $offsetAccount->id, 'debit' => 0, 'credit' => $amount, 'line_notes' => $note],
            ];
        } else {
            $lines = [
                ['account_id' => $offsetAccount->id, 'debit' => $amount, 'credit' => 0, 'line_notes' => $note],
                ['account_id' => $bankAccountId, 'debit' => 0, 'credit' => $amount, 'line_notes' => $note],
            ];
        }

        DB::transaction(function () use ($organizationId, $user, $reconciliation, $lines, $note, $amount) {
            $entry = $this->posting->createPosted(
                orgId: $organizationId,
                user: $user,
                entryNumber: 'BRECON-ADJ-'.$reconciliation->id.'-'.now()->format('His'),
                entryDate: (string) $reconciliation->period_end,
                lines: $lines,
                description: $note,
                branchId: $user->branch_id ? (int) $user->branch_id : null,
                referenceType: 'bank_reconciliation_adjustment',
                referenceId: (int) $reconciliation->id,
            );

            $bankLine = $entry->lines()->where('account_id', (int) $reconciliation->chart_of_account_id)->firstOrFail();

            BankReconciliationMatch::query()->create([
                'bank_reconciliation_id' => $reconciliation->id,
                'bank_statement_line_id' => null,
                'journal_entry_line_id' => (int) $bankLine->id,
                'match_type' => 'manual',
                'matched_amount' => $amount,
                'matched_by' => (int) $user->id,
                'matched_at' => now(),
            ]);
        });

        return $this->show($organizationId, $reconciliationId);
    }

    /**
     * @return array{account: ChartOfAccount, from_date: string, to_date: string, opening_balance: float, closing_balance: float, lines: array<int, array<string, mixed>>}
     */
    public function bankRegister(
        int $organizationId,
        int $accountId,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): array {
        $account = ChartOfAccount::query()
            ->where('organization_id', $organizationId)
            ->where('id', $accountId)
            ->firstOrFail();

        $toDate = $toDate ?: now()->toDateString();
        $fromDate = $fromDate ?: date('Y-m-d', strtotime($toDate.' -90 days'));

        $openingBalance = $this->glBalanceForAccount(
            $organizationId,
            $accountId,
            date('Y-m-d', strtotime($fromDate.' -1 day')),
        );

        $clearedLineIds = DB::table('bank_reconciliation_matches as m')
            ->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('r.organization_id', $organizationId)
            ->where('r.status', 'completed')
            ->pluck('m.journal_entry_line_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $organizationId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->whereBetween('je.entry_date', [$fromDate, $toDate])
            ->orderBy('je.entry_date')
            ->orderBy('jel.id')
            ->select([
                'jel.id',
                'jel.debit',
                'jel.credit',
                'jel.line_notes',
                'je.entry_date',
                'je.entry_number',
                'je.description',
                'je.reference_type',
                'je.reference_id',
            ])
            ->get();

        $running = $openingBalance;
        $lines = [];
        foreach ($rows as $row) {
            $signed = round((float) $row->debit - (float) $row->credit, 2);
            $running = round($running + $signed, 2);

            $lines[] = [
                'journal_entry_line_id' => (int) $row->id,
                'entry_date' => (string) $row->entry_date,
                'entry_number' => (string) $row->entry_number,
                'description' => trim((string) ($row->line_notes ?: $row->description)),
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'debit' => (float) $row->debit,
                'credit' => (float) $row->credit,
                'signed_amount' => $signed,
                'running_balance' => $running,
                'cleared' => isset($clearedLineIds[(int) $row->id]),
            ];
        }

        return [
            'account' => $account,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'opening_balance' => round($openingBalance, 2),
            'closing_balance' => round($running, 2),
            'lines' => $lines,
        ];
    }

    public function complete(int $organizationId, int $reconciliationId, User $user, ?string $notes = null): BankReconciliation
    {
        $reconciliation = $this->findReconciliation($organizationId, $reconciliationId);
        $this->assertEditable($reconciliation);

        $reconciliation = $this->refreshSummary($reconciliation);

        if (abs((float) $reconciliation->variance) > 0.02) {
            throw new InvalidArgumentException(
                'Reconciliation still has a variance of '
                .number_format((float) $reconciliation->variance, 2)
                .'. Match remaining items or adjust the statement balance before completing.',
            );
        }

        $reconciliation->update([
            'status' => 'completed',
            'notes' => $notes ?? $reconciliation->notes,
            'completed_by' => (int) $user->id,
            'completed_at' => now(),
        ]);

        return $reconciliation->fresh(['chartOfAccount']);
    }

    protected function refreshSummary(BankReconciliation $reconciliation): BankReconciliation
    {
        $orgId = (int) $reconciliation->organization_id;
        $accountId = (int) $reconciliation->chart_of_account_id;
        $periodEnd = (string) $reconciliation->period_end;

        $matchedJournalLineIds = $reconciliation->matches()
            ->pluck('journal_entry_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $bookItems = $this->bookTransactions($orgId, $accountId, $periodEnd, $matchedJournalLineIds);

        $outstandingReceipts = 0.0;
        $outstandingPayments = 0.0;
        foreach ($bookItems as $item) {
            $amount = (float) $item['signed_amount'];
            if ($amount > 0) {
                $outstandingReceipts += $amount;
            } elseif ($amount < 0) {
                $outstandingPayments += abs($amount);
            }
        }

        $bookBalance = $this->glBalanceForAccount($orgId, $accountId, $periodEnd);
        $adjustedBookBalance = round($bookBalance + $outstandingReceipts - $outstandingPayments, 2);
        $variance = round((float) $reconciliation->statement_balance - $adjustedBookBalance, 2);

        $reconciliation->fill([
            'book_balance' => $bookBalance,
            'outstanding_receipts' => round($outstandingReceipts, 2),
            'outstanding_payments' => round($outstandingPayments, 2),
            'adjusted_book_balance' => $adjustedBookBalance,
            'variance' => $variance,
        ])->save();

        return $reconciliation->fresh(['chartOfAccount']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    protected function importStatementLines(BankReconciliation $reconciliation, array $lines): void
    {
        foreach (array_values($lines) as $index => $line) {
            $amount = $this->resolveLineAmount($line);
            if ($amount === null) {
                continue;
            }

            BankStatementLine::query()->create([
                'bank_reconciliation_id' => $reconciliation->id,
                'line_date' => (string) ($line['line_date'] ?? $line['date'] ?? $reconciliation->period_end),
                'description' => trim((string) ($line['description'] ?? '')),
                'reference' => trim((string) ($line['reference'] ?? '')),
                'amount' => $amount,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<int, int>  $excludeJournalLineIds
     * @return array<int, array<string, mixed>>
     */
    protected function bookTransactions(
        int $organizationId,
        int $accountId,
        string $asOf,
        array $excludeJournalLineIds = [],
    ): array {
        $clearedLineIds = DB::table('bank_reconciliation_matches as m')
            ->join('bank_reconciliations as r', 'r.id', '=', 'm.bank_reconciliation_id')
            ->where('r.organization_id', $organizationId)
            ->where('r.status', 'completed')
            ->pluck('m.journal_entry_line_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $exclude = array_fill_keys(array_merge($clearedLineIds, $excludeJournalLineIds), true);

        $rows = DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.organization_id', $organizationId)
            ->where('je.status', 'posted')
            ->where('jel.account_id', $accountId)
            ->where('je.entry_date', '<=', $asOf)
            ->orderBy('je.entry_date')
            ->orderBy('jel.id')
            ->select([
                'jel.id',
                'jel.debit',
                'jel.credit',
                'jel.line_notes',
                'je.entry_date',
                'je.entry_number',
                'je.description',
                'je.reference_type',
                'je.reference_id',
            ])
            ->get();

        $items = [];
        foreach ($rows as $row) {
            if (isset($exclude[(int) $row->id])) {
                continue;
            }

            $signed = round((float) $row->debit - (float) $row->credit, 2);
            if (abs($signed) < 0.0001) {
                continue;
            }

            $items[] = [
                'journal_entry_line_id' => (int) $row->id,
                'entry_date' => (string) $row->entry_date,
                'entry_number' => (string) $row->entry_number,
                'description' => trim((string) ($row->line_notes ?: $row->description)),
                'reference_type' => $row->reference_type,
                'reference_id' => $row->reference_id,
                'signed_amount' => $signed,
                'amount' => abs($signed),
                'direction' => $signed >= 0 ? 'receipt' : 'payment',
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bookItems
     * @return array<int, array<string, mixed>>
     */
    protected function buildMatchSuggestions(BankReconciliation $reconciliation, array $bookItems): array
    {
        $statementLines = $reconciliation->statementLines()
            ->where('match_status', 'unmatched')
            ->orderBy('line_date')
            ->get();

        $suggestions = [];
        $usedBookIds = [];

        foreach ($statementLines as $statementLine) {
            $targetAmount = abs((float) $statementLine->amount);
            $targetDate = (string) $statementLine->line_date;

            foreach ($bookItems as $bookItem) {
                $bookId = (int) $bookItem['journal_entry_line_id'];
                if (isset($usedBookIds[$bookId])) {
                    continue;
                }

                if (abs((float) $bookItem['amount'] - $targetAmount) > 0.02) {
                    continue;
                }

                if (! $this->datesWithinTolerance($targetDate, (string) $bookItem['entry_date'], 7)) {
                    continue;
                }

                $suggestions[] = [
                    'bank_statement_line_id' => (int) $statementLine->id,
                    'journal_entry_line_id' => $bookId,
                    'statement_amount' => (float) $statementLine->amount,
                    'book_amount' => (float) $bookItem['signed_amount'],
                    'confidence' => $this->referenceScore($statementLine, $bookItem),
                ];
                $usedBookIds[$bookId] = true;
                break;
            }
        }

        usort($suggestions, fn ($a, $b) => ($b['confidence'] <=> $a['confidence']));

        return $suggestions;
    }

    protected function referenceScore(BankStatementLine $statementLine, array $bookItem): int
    {
        $score = 50;
        $reference = strtolower(trim((string) $statementLine->reference));
        $description = strtolower(trim((string) $statementLine->description));
        $haystack = strtolower(trim((string) ($bookItem['description'] ?? '')));

        if ($reference !== '' && str_contains($haystack, $reference)) {
            $score += 30;
        }
        if ($description !== '' && str_contains($haystack, substr($description, 0, 12))) {
            $score += 10;
        }

        return $score;
    }

    protected function datesWithinTolerance(string $left, string $right, int $days): bool
    {
        return abs(strtotime($left) - strtotime($right)) <= ($days * 86400);
    }

    protected function signedBookAmount(JournalEntryLine $line): float
    {
        return round((float) $line->debit - (float) $line->credit, 2);
    }

    protected function findBankAccount(int $organizationId, int $accountId): ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('organization_id', $organizationId)
            ->where('id', $accountId)
            ->where('account_type', 'asset')
            ->firstOrFail();
    }

    protected function findReconciliation(int $organizationId, int $reconciliationId): BankReconciliation
    {
        return BankReconciliation::query()
            ->with(['chartOfAccount', 'statementLines', 'matches'])
            ->where('organization_id', $organizationId)
            ->where('id', $reconciliationId)
            ->firstOrFail();
    }

    protected function findBookLine(int $organizationId, int $accountId, int $journalEntryLineId): JournalEntryLine
    {
        return JournalEntryLine::query()
            ->where('id', $journalEntryLineId)
            ->whereHas('journalEntry', function ($query) use ($organizationId) {
                $query->where('organization_id', $organizationId)->where('status', 'posted');
            })
            ->where('account_id', $accountId)
            ->firstOrFail();
    }

    protected function assertEditable(BankReconciliation $reconciliation): void
    {
        if ($reconciliation->status !== 'in_progress') {
            throw new InvalidArgumentException('Only in-progress reconciliations can be changed.');
        }
    }

  /**
     * @param  array<int, string>|null  $header
     * @param  array<int, string|null>  $cells
     * @return array<string, mixed>|null
     */
    protected function mapCsvRow(?array $header, array $cells): ?array
    {
        if ($header !== null) {
            $mapped = [];
            foreach ($header as $index => $key) {
                $mapped[$key] = trim((string) ($cells[$index] ?? ''));
            }
        } else {
            $mapped = [
                'date' => trim((string) ($cells[0] ?? '')),
                'description' => trim((string) ($cells[1] ?? '')),
                'reference' => trim((string) ($cells[2] ?? '')),
                'amount' => trim((string) ($cells[3] ?? '')),
            ];
        }

        $date = $mapped['line_date'] ?? $mapped['date'] ?? $mapped['transaction_date'] ?? '';
        $description = $mapped['description'] ?? $mapped['narrative'] ?? $mapped['details'] ?? '';
        $reference = $mapped['reference'] ?? $mapped['ref'] ?? $mapped['cheque_number'] ?? '';
        $amount = $this->resolveLineAmount($mapped);

        if ($date === '' || $amount === null) {
            return null;
        }

        return [
            'line_date' => $this->normalizeDate($date),
            'description' => $description,
            'reference' => $reference,
            'amount' => $amount,
        ];
    }

    /** @param array<string, mixed> $line */
    protected function resolveLineAmount(array $line): ?float
    {
        if (isset($line['amount']) && $line['amount'] !== '') {
            return round((float) str_replace([',', ' '], '', (string) $line['amount']), 2);
        }

        $debit = (float) str_replace([',', ' '], '', (string) ($line['debit'] ?? $line['money_in'] ?? 0));
        $credit = (float) str_replace([',', ' '], '', (string) ($line['credit'] ?? $line['money_out'] ?? 0));

        if ($debit > 0 && $credit <= 0) {
            return round($debit, 2);
        }
        if ($credit > 0 && $debit <= 0) {
            return round($credit * -1, 2);
        }

        return null;
    }

    /** @param array<int, string> $header */
    protected function looksLikeHeader(array $header): bool
    {
        foreach ($header as $cell) {
            if (in_array($cell, ['date', 'line_date', 'transaction_date', 'description', 'reference', 'amount', 'debit', 'credit'], true)) {
                return true;
            }
        }

        return false;
    }

    protected function normalizeDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        $timestamp = strtotime($value);

        return $timestamp ? date('Y-m-d', $timestamp) : $value;
    }

    protected function serializeReconciliation(BankReconciliation $reconciliation): BankReconciliation
    {
        $reconciliation->setAttribute('account_code', $reconciliation->chartOfAccount?->account_code);
        $reconciliation->setAttribute('account_name', $reconciliation->chartOfAccount?->account_name);

        return $reconciliation;
    }

    protected function serializeStatementLine(BankStatementLine $line): array
    {
        return [
            'id' => (int) $line->id,
            'line_date' => (string) $line->line_date?->format('Y-m-d'),
            'description' => $line->description,
            'reference' => $line->reference,
            'amount' => (float) $line->amount,
            'match_status' => $line->match_status,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $statementLines
     * @param  array<int, array<string, mixed>>  $bookItems
     */
    protected function serializeMatch(
        BankReconciliationMatch $match,
        array $statementLines,
        array $bookItems,
    ): array {
        $statement = collect($statementLines)->firstWhere('id', (int) $match->bank_statement_line_id);
        $book = collect($bookItems)->firstWhere('journal_entry_line_id', (int) $match->journal_entry_line_id);

        if (! $book) {
            $journalLine = JournalEntryLine::query()
                ->with('journalEntry:id,entry_date,entry_number,description')
                ->find($match->journal_entry_line_id);
            if ($journalLine) {
                $signed = round((float) $journalLine->debit - (float) $journalLine->credit, 2);
                $book = [
                    'journal_entry_line_id' => (int) $journalLine->id,
                    'entry_date' => (string) $journalLine->journalEntry?->entry_date,
                    'entry_number' => (string) $journalLine->journalEntry?->entry_number,
                    'description' => trim((string) ($journalLine->line_notes ?: $journalLine->journalEntry?->description)),
                    'signed_amount' => $signed,
                    'amount' => abs($signed),
                    'direction' => $signed >= 0 ? 'receipt' : 'payment',
                ];
            }
        }

        return [
            'id' => (int) $match->id,
            'bank_statement_line_id' => $match->bank_statement_line_id ? (int) $match->bank_statement_line_id : null,
            'journal_entry_line_id' => (int) $match->journal_entry_line_id,
            'match_type' => $match->match_type,
            'matched_amount' => (float) $match->matched_amount,
            'statement' => $statement,
            'book' => $book,
        ];
    }
}
