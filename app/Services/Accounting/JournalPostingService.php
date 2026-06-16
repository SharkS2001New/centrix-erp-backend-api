<?php

namespace App\Services\Accounting;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Organization;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class JournalPostingService
{
    public function __construct(
        protected FiscalPeriodService $fiscalPeriods,
    ) {}
    /** @param  array<int, array<string, mixed>>  $lines */
    public function assertBalanced(array $lines): void
    {
        $debits = collect($lines)->sum(fn ($line) => (float) ($line['debit'] ?? 0));
        $credits = collect($lines)->sum(fn ($line) => (float) ($line['credit'] ?? 0));

        if (round($debits, 2) !== round($credits, 2)) {
            throw ValidationException::withMessages([
                'lines' => ['Debits must equal credits.'],
            ]);
        }

        if ($debits <= 0) {
            throw ValidationException::withMessages([
                'lines' => ['Entry total must be greater than zero.'],
            ]);
        }
    }

    /** @param  list<int>  $accountIds */
    public function assertAccountsInOrg(int $orgId, array $accountIds): void
    {
        $accountIds = array_values(array_unique(array_filter($accountIds)));
        if ($accountIds === []) {
            throw ValidationException::withMessages([
                'lines' => ['At least one account is required.'],
            ]);
        }

        $validCount = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->count();

        if ($validCount !== count($accountIds)) {
            throw ValidationException::withMessages([
                'lines' => ['One or more accounts are invalid or inactive for this organization.'],
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createDraft(
        int $orgId,
        User $user,
        string $entryNumber,
        string $entryDate,
        array $lines,
        ?string $description = null,
        ?int $branchId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): JournalEntry {
        $this->assertBalanced($lines);
        $this->assertAccountsInOrg($orgId, collect($lines)->pluck('account_id')->map(fn ($id) => (int) $id)->all());
        $this->fiscalPeriods->assertDateIsOpen($orgId, $entryDate);

        if (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $entryNumber)->exists()) {
            throw ValidationException::withMessages([
                'entry_number' => ['Entry number already exists.'],
            ]);
        }

        return DB::transaction(function () use ($orgId, $user, $entryNumber, $entryDate, $lines, $description, $branchId, $referenceType, $referenceId) {
            $entry = new JournalEntry([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'created_by' => $user->id,
            ]);
            $entry->forceFill(['status' => 'draft'])->save();

            $this->persistLines($entry, $lines);

            return $entry->load(['lines.account']);
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createPosted(
        int $orgId,
        User $user,
        string $entryNumber,
        string $entryDate,
        array $lines,
        ?string $description = null,
        ?int $branchId = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): JournalEntry {
        $this->assertBalanced($lines);
        $this->assertAccountsInOrg($orgId, collect($lines)->pluck('account_id')->map(fn ($id) => (int) $id)->all());
        $this->fiscalPeriods->assertDateIsOpen($orgId, $entryDate);

        if (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $entryNumber)->exists()) {
            return JournalEntry::query()
                ->where('organization_id', $orgId)
                ->where('entry_number', $entryNumber)
                ->firstOrFail();
        }

        return DB::transaction(function () use ($orgId, $user, $entryNumber, $entryDate, $lines, $description, $branchId, $referenceType, $referenceId) {
            $entry = new JournalEntry([
                'organization_id' => $orgId,
                'branch_id' => $branchId,
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => $description,
                'created_by' => $user->id,
            ]);
            $entry->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
            ])->save();

            $this->persistLines($entry, $lines);

            return $entry->load(['lines.account']);
        });
    }

    public function postDraft(JournalEntry $entry): JournalEntry
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => ['Only draft entries can be posted.'],
            ]);
        }

        $entry->loadMissing('lines');
        $this->assertBalanced($entry->lines->map(fn ($line) => [
            'debit' => $line->debit,
            'credit' => $line->credit,
        ])->all());
        $this->fiscalPeriods->assertDateIsOpen((int) $entry->organization_id, (string) $entry->entry_date);

        $entry->forceFill([
            'status' => 'posted',
            'posted_at' => now(),
        ])->save();

        return $entry->fresh(['lines.account']);
    }

    /** @return array{original: JournalEntry, reversal: JournalEntry} */
    public function reversePosted(JournalEntry $entry, User $user): array
    {
        if ($entry->status !== 'posted') {
            throw ValidationException::withMessages([
                'status' => ['Only posted entries can be reversed.'],
            ]);
        }

        $entry->loadMissing('lines');
        $orgId = (int) $entry->organization_id;
        $this->fiscalPeriods->assertDateIsOpen($orgId, now()->toDateString());
        $reversalNumber = $this->nextReversalNumber($orgId, $entry->entry_number);

        return DB::transaction(function () use ($entry, $user, $reversalNumber) {
            $reversal = new JournalEntry([
                'organization_id' => $entry->organization_id,
                'branch_id' => $entry->branch_id,
                'entry_number' => $reversalNumber,
                'entry_date' => now()->toDateString(),
                'reference_type' => 'journal_reversal',
                'reference_id' => $entry->id,
                'description' => 'Reversal of '.$entry->entry_number.($entry->description ? ': '.$entry->description : ''),
                'created_by' => $user->id,
            ]);
            $reversal->forceFill([
                'status' => 'posted',
                'posted_at' => now(),
            ])->save();

            foreach ($entry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'line_notes' => $line->line_notes,
                ]);
            }

            $entry->forceFill(['status' => 'void'])->save();

            return [
                'original' => $entry->fresh(['lines.account']),
                'reversal' => $reversal->load(['lines.account']),
            ];
        });
    }

    public function resolveAccount(int $orgId, string $accountCode): ?ChartOfAccount
    {
        return ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', $accountCode)
            ->where('is_active', true)
            ->first();
    }

    /** @return array<string, string> */
    public function defaultAccountCodes(?CapabilityGate $gate = null): array
    {
        return $this->accountCodes($gate);
    }

    /** @return array<string, string> */
    public function accountCodes(?CapabilityGate $gate = null): array
    {
        $defaults = config('erp.module_settings_defaults.accounting.account_codes', []);
        if (! $gate) {
            return $defaults;
        }

        $custom = $gate->moduleSettings('accounting')['account_codes'] ?? [];

        return array_merge($defaults, is_array($custom) ? $custom : []);
    }

    /** @return array<string, string> */
    public function accountCodesForOrganizationId(int $orgId): array
    {
        $organization = Organization::find($orgId);

        return $organization
            ? $this->accountCodes(app(CapabilityGate::class)->forOrganization($organization))
            : config('erp.module_settings_defaults.accounting.account_codes', []);
    }

    /** @return array<string, string> */
    public function paymentMethodAccounts(?CapabilityGate $gate = null): array
    {
        $defaults = config('erp.module_settings_defaults.accounting.payment_method_accounts', []);
        if (! $gate) {
            return $defaults;
        }

        $custom = $gate->moduleSettings('accounting')['payment_method_accounts'] ?? [];

        return array_merge($defaults, is_array($custom) ? $custom : []);
    }

    protected function nextReversalNumber(int $orgId, string $baseNumber): string
    {
        $candidate = $baseNumber.'-REV';
        $suffix = 1;

        while (JournalEntry::query()->where('organization_id', $orgId)->where('entry_number', $candidate)->exists()) {
            $suffix++;
            $candidate = $baseNumber.'-REV'.$suffix;
        }

        return $candidate;
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function persistLines(JournalEntry $entry, array $lines): void
    {
        foreach ($lines as $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->id,
                'account_id' => (int) $line['account_id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'line_notes' => $line['line_notes'] ?? null,
            ]);
        }
    }
}
