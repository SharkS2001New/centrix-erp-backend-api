<?php

namespace App\Services\Accounting;

use App\Models\AccountingExportQueue;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Erp\CapabilityGate;

class ReferenceJournalReversalService
{
    public function __construct(
        protected JournalPostingService $posting,
        protected AccountingSettingsResolver $settings,
        protected JournalExportService $exports,
    ) {}

    /** @return array{original: JournalEntry, reversal: JournalEntry}|null */
    public function reverseIfEnabled(
        string $referenceType,
        int $referenceId,
        User $user,
        CapabilityGate $gate,
    ): ?array {
        if (! $gate->enabled('accounting')) {
            return null;
        }

        $orgId = (int) $user->organization_id;
        $financeSettings = $this->settings->fromFinanceSettings($gate->moduleSettings('finance'));

        if ($financeSettings->usesExternalLedger()) {
            return $this->reverseExternalExport($referenceType, $referenceId, $user, $gate, $orgId);
        }

        $entry = JournalEntry::query()
            ->where('organization_id', $orgId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->where('status', 'posted')
            ->first();

        if (! $entry) {
            return null;
        }

        return $this->posting->reversePosted($entry, $user);
    }

    /** @return array{original: JournalEntry, reversal: JournalEntry}|null */
    protected function reverseExternalExport(
        string $referenceType,
        int $referenceId,
        User $user,
        CapabilityGate $gate,
        int $orgId,
    ): ?array {
        $item = AccountingExportQueue::query()
            ->where('organization_id', $orgId)
            ->where('reference_type', $referenceType)
            ->where('reference_id', $referenceId)
            ->whereIn('status', ['pending', 'exported'])
            ->orderByDesc('id')
            ->first();

        if (! $item) {
            return null;
        }

        if ($item->status === 'pending') {
            $item->forceFill([
                'status' => 'failed',
                'last_error' => 'Source transaction cancelled before export.',
            ])->save();

            return null;
        }

        $this->exports->queueReversal($item, $gate);

        return null;
    }
}
