<?php

namespace App\Console\Commands;

use App\Models\SystemIssueReport;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneSystemIssueReportsCommand extends Command
{
    protected $signature = 'erp:prune-system-issue-reports
                            {--days= : Override retention days (default from config)}
                            {--resolved-days= : Override resolved issue retention days (default from config)}
                            {--dry-run : Count matching rows without deleting}';

    protected $description = 'Delete resolved reports after a week and unresolved reports after the configured retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('system_issues.retention_days', 30));
        if ($days < 1) {
            $this->error('Retention days must be at least 1.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days)->startOfDay();
        $resolvedDays = (int) ($this->option('resolved-days') ?: config('system_issues.resolved_retention_days', 7));
        if ($resolvedDays < 1) {
            $this->error('Resolved retention days must be at least 1.');

            return self::FAILURE;
        }

        $resolvedCutoff = Carbon::now()->subDays($resolvedDays)->startOfDay();
        $resolvedQuery = SystemIssueReport::query()
            ->where('status', 'resolved')
            ->where(function ($query) use ($resolvedCutoff) {
                $query->where('resolved_at', '<', $resolvedCutoff)
                    ->orWhere(function ($fallback) use ($resolvedCutoff) {
                        $fallback->whereNull('resolved_at')
                            ->where('created_at', '<', $resolvedCutoff);
                    });
            });
        $unresolvedQuery = SystemIssueReport::query()
            ->where('status', '!=', 'resolved')
            ->where('created_at', '<', $cutoff);
        $count = (clone $resolvedQuery)->count() + (clone $unresolvedQuery)->count();

        if ($this->option('dry-run')) {
            $this->info(
                "Would delete {$count} report(s): resolved at least {$resolvedDays} day(s) ago "
                ."and unresolved reports older than {$days} day(s).",
            );

            return self::SUCCESS;
        }

        $deleted = $resolvedQuery->delete() + $unresolvedQuery->delete();

        $this->info(
            "Deleted {$deleted} report(s): resolved at least {$resolvedDays} day(s) ago "
            ."and unresolved reports older than {$days} day(s).",
        );

        return self::SUCCESS;
    }
}
