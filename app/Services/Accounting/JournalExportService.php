<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\TillFloatSession;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JournalExportService
{
    public function __construct(
        protected SaleJournalBuilder $saleBuilder,
        protected AccountingSettingsResolver $settings,
    ) {}

    public function queueSale(Sale $sale, User $user, CapabilityGate $gate): ?AccountingExportQueue
    {
        $settings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if (! $settings->exportsEnabled()) {
            return null;
        }

        $provider = $settings->provider() ?? 'quickbooks';
        $lines = $this->saleBuilder->buildLines($sale);
        if ($lines === null || $lines === []) {
            return null;
        }

        return $this->queuePayload(
            orgId: (int) $sale->organization_id,
            provider: $provider,
            entryNumber: 'SALE-'.$sale->order_num,
            entryDate: now()->toDateString(),
            referenceType: 'sale',
            referenceId: (int) $sale->id,
            description: 'Sale #'.$sale->order_num,
            lines: $this->linesWithAccountCodes((int) $sale->organization_id, $lines),
        );
    }

    /** @param  array<int, array<string, mixed>>  $rawLines */
    public function queueTillVariance(
        TillFloatSession $session,
        User $user,
        CapabilityGate $gate,
        array $rawLines,
        float $variance,
    ): ?AccountingExportQueue {
        $settings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if (! $settings->exportsEnabled()) {
            return null;
        }

        $provider = $settings->provider() ?? 'quickbooks';

        return $this->queuePayload(
            orgId: (int) $user->organization_id,
            provider: $provider,
            entryNumber: 'TILL-VAR-'.$session->id,
            entryDate: now()->toDateString(),
            referenceType: 'till_float_session',
            referenceId: (int) $session->id,
            description: sprintf('Till session #%d variance (%s)', $session->id, number_format($variance, 2)),
            lines: $this->linesWithAccountCodes((int) $user->organization_id, $rawLines),
        );
    }

    public function queueFromNativeJournal(JournalEntry $entry, CapabilityGate $gate): ?AccountingExportQueue
    {
        $settings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if (! $settings->exportsEnabled()) {
            return null;
        }

        $entry->loadMissing(['lines.account']);
        $provider = $settings->provider() ?? 'quickbooks';
        $lines = $entry->lines->map(fn ($line) => [
            'account_code' => $line->account?->account_code,
            'account_name' => $line->account?->account_name,
            'debit' => (float) $line->debit,
            'credit' => (float) $line->credit,
            'line_notes' => $line->line_notes,
        ])->all();

        return $this->queuePayload(
            orgId: (int) $entry->organization_id,
            provider: $provider,
            entryNumber: $entry->entry_number,
            entryDate: (string) $entry->entry_date,
            referenceType: (string) ($entry->reference_type ?: 'journal_entry'),
            referenceId: (int) ($entry->reference_id ?: $entry->id),
            description: $entry->description,
            lines: $lines,
        );
    }

    /** @return array{processed: int, exported: int, failed: int} */
    public function processPending(int $orgId, ?string $provider = null): array
    {
        $provider = $provider ?? 'quickbooks';
        $processed = 0;
        $exported = 0;
        $failed = 0;

        $items = AccountingExportQueue::query()
            ->where('organization_id', $orgId)
            ->where('provider', $provider)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($items as $item) {
            $processed++;
            try {
                $externalId = app(ExternalAccountingExportDriverResolver::class)
                    ->resolve($item->provider)
                    ->exportJournal($item);
                $item->forceFill([
                    'status' => 'exported',
                    'external_journal_id' => $externalId,
                    'exported_at' => now(),
                    'last_error' => null,
                ])->save();
                $exported++;
            } catch (\Throwable $e) {
                $item->forceFill([
                    'status' => 'failed',
                    'last_error' => $e->getMessage(),
                ])->save();
                $failed++;
            }
        }

        return compact('processed', 'exported', 'failed');
    }

    /** @return array{reset: int, processed: int, exported: int, failed: int} */
    public function retryFailed(int $orgId, ?string $provider = null): array
    {
        $provider = $provider ?? 'quickbooks';

        $reset = AccountingExportQueue::query()
            ->where('organization_id', $orgId)
            ->where('provider', $provider)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'last_error' => null,
            ]);

        $result = $this->processPending($orgId, $provider);

        return array_merge(['reset' => $reset], $result);
    }

    public function queueReversal(AccountingExportQueue $original, CapabilityGate $gate): ?AccountingExportQueue
    {
        $settings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if (! $settings->exportsEnabled()) {
            return null;
        }

        $lines = is_array($original->lines) ? $original->lines : [];
        if ($lines === []) {
            return null;
        }

        $inverted = collect($lines)->map(function ($line) {
            return [
                'account_code' => $line['account_code'] ?? null,
                'account_name' => $line['account_name'] ?? null,
                'debit' => (float) ($line['credit'] ?? 0),
                'credit' => (float) ($line['debit'] ?? 0),
                'line_notes' => 'Reversal: '.($line['line_notes'] ?? ''),
            ];
        })->all();

        $provider = $original->provider ?: ($settings->provider() ?? 'quickbooks');
        $reversalNumber = $original->entry_number.'-REV';

        return AccountingExportQueue::firstOrCreate(
            [
                'organization_id' => $original->organization_id,
                'provider' => $provider,
                'reference_type' => 'journal_reversal',
                'reference_id' => (int) $original->id,
            ],
            [
                'entry_number' => $reversalNumber,
                'entry_date' => now()->toDateString(),
                'description' => 'Reversal of '.$original->entry_number,
                'lines' => $inverted,
                'status' => 'pending',
            ],
        );
    }

    /** @return Collection<int, AccountingExportQueue> */
    public function listQueue(int $orgId, ?string $status = null): Collection
    {
        $query = AccountingExportQueue::query()
            ->where('organization_id', $orgId)
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }

        return $query->limit(200)->get();
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function queueGeneric(
        int $orgId,
        CapabilityGate $gate,
        string $entryNumber,
        string $entryDate,
        string $referenceType,
        int $referenceId,
        ?string $description,
        array $lines,
    ): ?AccountingExportQueue {
        $settings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));
        if (! $settings->exportsEnabled()) {
            return null;
        }

        $provider = $settings->provider() ?? 'quickbooks';

        return $this->queuePayload(
            orgId: $orgId,
            provider: $provider,
            entryNumber: $entryNumber,
            entryDate: $entryDate,
            referenceType: $referenceType,
            referenceId: $referenceId,
            description: $description,
            lines: $this->linesWithAccountCodes($orgId, $lines),
        );
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function queuePayload(
        int $orgId,
        string $provider,
        string $entryNumber,
        string $entryDate,
        string $referenceType,
        int $referenceId,
        ?string $description,
        array $lines,
    ): AccountingExportQueue {
        return AccountingExportQueue::firstOrCreate(
            [
                'organization_id' => $orgId,
                'provider' => $provider,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ],
            [
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate,
                'description' => $description,
                'lines' => $lines,
                'status' => 'pending',
            ],
        );
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    protected function linesWithAccountCodes(int $orgId, array $lines): array
    {
        $accounts = DB::table('chart_of_accounts')
            ->where('organization_id', $orgId)
            ->whereIn('id', collect($lines)->pluck('account_id'))
            ->get()
            ->keyBy('id');

        return collect($lines)->map(function ($line) use ($accounts) {
            $account = $accounts->get($line['account_id']);

            return [
                'account_code' => $account->account_code ?? null,
                'account_name' => $account->account_name ?? null,
                'debit' => (float) ($line['debit'] ?? 0),
                'credit' => (float) ($line['credit'] ?? 0),
                'line_notes' => $line['line_notes'] ?? null,
            ];
        })->all();
    }
}
