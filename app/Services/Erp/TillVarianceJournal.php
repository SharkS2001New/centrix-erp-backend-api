<?php

namespace App\Services\Erp;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\TillFloatSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TillVarianceJournal
{
    public function postIfEnabled(TillFloatSession $session, User $user, ?float $variance): ?JournalEntry
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
        $cash = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '1000')
            ->first();
        $varianceAccount = ChartOfAccount::query()
            ->where('organization_id', $orgId)
            ->where('account_code', '5100')
            ->first();

        if (! $cash || ! $varianceAccount) {
            return null;
        }

        $entryNumber = 'TILL-VAR-'.$session->id;
        if (JournalEntry::where('organization_id', $orgId)->where('entry_number', $entryNumber)->exists()) {
            return null;
        }

        $amount = round(abs($variance), 2);
        $isShort = $variance < 0;

        return DB::transaction(function () use ($session, $user, $orgId, $cash, $varianceAccount, $entryNumber, $amount, $isShort, $variance) {
            $entry = JournalEntry::create([
                'organization_id' => $orgId,
                'branch_id' => $session->branch_id,
                'entry_number' => $entryNumber,
                'entry_date' => now()->toDateString(),
                'reference_type' => 'till_float_session',
                'reference_id' => $session->id,
                'description' => sprintf(
                    'Till session #%d %s variance (%s)',
                    $session->id,
                    $isShort ? 'cash short' : 'cash over',
                    number_format($variance, 2),
                ),
                'status' => 'posted',
                'created_by' => $user->id,
                'posted_at' => now(),
            ]);

            if ($isShort) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $varianceAccount->id,
                    'debit' => $amount,
                    'credit' => 0,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $cash->id,
                    'debit' => 0,
                    'credit' => $amount,
                ]);
            } else {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $cash->id,
                    'debit' => $amount,
                    'credit' => 0,
                ]);
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $varianceAccount->id,
                    'debit' => 0,
                    'credit' => $amount,
                ]);
            }

            return $entry;
        });
    }
}
