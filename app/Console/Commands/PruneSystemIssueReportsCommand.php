<?php

namespace App\Console\Commands;

use App\Models\SystemIssueReport;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneSystemIssueReportsCommand extends Command
{
    protected $signature = 'erp:prune-system-issue-reports
                            {--days= : Override retention days (default from config)}
                            {--dry-run : Count matching rows without deleting}';

    protected $description = 'Delete system errors & reports older than the configured retention period (default 30 days)';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('system_issues.retention_days', 30));
        if ($days < 1) {
            $this->error('Retention days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days)->startOfDay();
        $query = SystemIssueReport::query()->where('created_at', '<', $cutoff);
        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} system issue report(s) older than {$days} day(s) (before {$cutoff->toDateString()}).");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("Deleted {$deleted} system issue report(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
