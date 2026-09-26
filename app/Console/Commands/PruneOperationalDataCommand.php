<?php

namespace App\Console\Commands;

use App\Services\Retention\OperationalDataPruneService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class PruneOperationalDataCommand extends Command
{
    protected $signature = 'erp:prune-operational-data
                            {--dry-run : Count matching rows without deleting}
                            {--days= : Delete rows older than this many days (overrides saved retention timers for this run)}
                            {--limit= : Max rows to delete this run (across selected targets)}
                            {--only=* : Limit to table aliases (hikvision_agent_commands, hikvision_access_events, employee_attendance, kra_agent_commands, stock_reservations, audit_logs, cancelled_sales, expired_sales)}
                            {--optimize : Run OPTIMIZE TABLE on pruned retention tables afterward}
                            {--source=cli : Who triggered this run (schedule|cli|manual)}';

    protected $description = 'Prune operational tables older than retention (or --days). Optionally limit with --only / --limit.';

    public function handle(OperationalDataPruneService $pruner): int
    {
        \App\Services\Retention\DataRetentionSettingsResolver::applyToRuntime();

        $dryRun = (bool) $this->option('dry-run');
        $daysOption = $this->option('days');
        $days = $daysOption !== null && $daysOption !== '' ? (int) $daysOption : null;
        if ($days !== null && ($days < 1 || $days > 365)) {
            $this->error('--days must be between 1 and 365.');

            return self::FAILURE;
        }

        $limitOption = $this->option('limit');
        $maxRows = $limitOption !== null && $limitOption !== '' ? (int) $limitOption : null;
        if ($maxRows !== null && ($maxRows < 1 || $maxRows > 500000)) {
            $this->error('--limit must be between 1 and 500000.');

            return self::FAILURE;
        }

        $only = array_values(array_filter(
            array_map('strval', (array) $this->option('only')),
            static fn (string $v) => trim($v) !== '',
        ));

        $source = strtolower(trim((string) $this->option('source')));
        if (! in_array($source, ['schedule', 'cli', 'manual'], true)) {
            $source = 'cli';
        }

        try {
            $results = $pruner->pruneTargets(
                $only === [] ? null : $only,
                $days,
                $dryRun,
                function (array $payload): void {
                    $message = (string) ($payload['message'] ?? '');
                    if ($message === '') {
                        return;
                    }
                    $phase = (string) ($payload['phase'] ?? '');
                    $event = (string) ($payload['event'] ?? 'step');
                    if ($event === 'status' || $phase === 'done') {
                        $this->info($message);
                    } else {
                        $this->line($message);
                    }
                },
                $maxRows,
                $source,
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $optimized = [];
        if (! $dryRun && (bool) $this->option('optimize')) {
            $tables = $only === [] ? null : $only;
            if ($tables !== null) {
                $tables = array_values(array_unique(array_map(
                    static fn (string $t) => $t === 'employee_attendance' ? 'employee_attendance' : $t,
                    $tables,
                )));
                if (in_array('employee_attendance', $tables, true)) {
                    $tables[] = 'employee_clock_sessions';
                }
            }
            $optimizeResults = $pruner->optimizeRetentionTables(
                $tables,
                function (array $payload): void {
                    $message = (string) ($payload['message'] ?? '');
                    if ($message !== '') {
                        $this->line($message);
                    }
                },
            );
            $optimized = array_column($optimizeResults, 'name');
            if ($optimized === []) {
                $this->warn('No tables were optimized.');
            }
            $pruner->recordLastRun([
                'source' => $source,
                'dry_run' => false,
                'total' => array_sum($results),
                'deleted' => $results,
                'targets' => $only === [] ? OperationalDataPruneService::TARGETS : $only,
                'days' => $days,
                'max_rows' => $maxRows,
                'optimized_tables' => $optimized,
            ]);
        }

        $this->info($dryRun
            ? 'Dry run complete — no rows were deleted.'
            : 'Operational data prune complete.');

        return self::SUCCESS;
    }
}
