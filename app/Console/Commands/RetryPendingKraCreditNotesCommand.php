<?php

namespace App\Console\Commands;

use App\Services\Sales\CreditNoteService;
use App\Support\AppTimezone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daytime hourly retry for return credit notes that soft-failed while Centrix KRA Agent
 * / Comstore were offline.
 */
class RetryPendingKraCreditNotesCommand extends Command
{
    protected $signature = 'erp:retry-pending-kra-credit-notes
                            {--organization_id= : Limit to one organization}
                            {--limit=50 : Max credit notes to process}
                            {--force : Run even outside daytime window}';

    protected $description = 'Retry queued KRA credit notes for approved returns (daytime every 15 minutes)';

    public function handle(CreditNoteService $credits): int
    {
        $force = (bool) $this->option('force');
        $now = AppTimezone::now();
        $hour = (int) $now->format('G');
        // Business daytime (Africa/Nairobi): 08:00–18:59 inclusive.
        if (! $force && ($hour < 8 || $hour > 18)) {
            $this->info("Outside daytime window ({$now->toDateTimeString()}); skipping. Use --force to run anyway.");

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $orgFilter = $this->option('organization_id') ? (int) $this->option('organization_id') : null;

        $stats = $credits->retryPendingKraCredits($limit, $orgFilter);

        $this->info(sprintf(
            'KRA credit retry: attempted=%d succeeded=%d still_pending=%d skipped=%d',
            $stats['attempted'],
            $stats['succeeded'],
            $stats['still_pending'],
            $stats['skipped'],
        ));

        Log::info('erp:retry-pending-kra-credit-notes', $stats);

        return self::SUCCESS;
    }
}
