<?php

namespace App\Console\Commands;

use App\Services\Platform\PlatformMailboxService;
use Illuminate\Console\Command;

class PrunePlatformMailMessagesCommand extends Command
{
    protected $signature = 'erp:prune-platform-mail
                            {--months=3 : Delete local mailbox copies older than this many months}
                            {--dry-run : Count matching rows without deleting}';

    protected $description = 'Auto-delete local platform mailbox messages (and AI reply memory) older than 3 months';

    public function handle(PlatformMailboxService $mailbox): int
    {
        $months = (int) ($this->option('months') ?: 3);
        if ($months < 1) {
            $this->error('Months must be at least 1.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $cutoff = now()->subMonths($months);
            $count = \App\Models\PlatformMailMessage::query()
                ->where(function ($q) use ($cutoff) {
                    $q->where(function ($inner) use ($cutoff) {
                        $inner->whereNotNull('received_at')->where('received_at', '<', $cutoff);
                    })->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('received_at')
                            ->whereNotNull('sent_at')
                            ->where('sent_at', '<', $cutoff);
                    })->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNull('received_at')
                            ->whereNull('sent_at')
                            ->where('created_at', '<', $cutoff);
                    });
                })
                ->count();
            $this->info("Would delete {$count} local platform mail message(s) older than {$months} month(s) (before {$cutoff->toDateString()}).");

            return self::SUCCESS;
        }

        $result = $mailbox->pruneLocalMessages($months);
        $this->info("Deleted {$result['deleted']} local platform mail message(s) older than {$months} month(s).");

        return self::SUCCESS;
    }
}
