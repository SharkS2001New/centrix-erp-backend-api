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
                            {--only=* : Limit to table aliases (hikvision_agent_commands, hikvision_access_events, employee_attendance, kra_agent_commands, stock_reservations, audit_logs, cancelled_sales, expired_sales)}
                            {--optimize : Run OPTIMIZE TABLE on pruned retention tables afterward}';

    protected $description = 'Prune operational tables older than retention (or --days). Optionally limit with --only.';

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

        $only = array_values(array_filter(
            array_map('strval', (array) $this->option('only')),
            static fn (string $v) => trim($v) !== '',
        ));

        try {
            $pruner->pruneTargets(
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
            );
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $dryRun && (bool) $this->option('optimize')) {
            $tables = $only === [] ? null : $only;
            // Map logical targets that share optimize tables
            if ($tables !== null) {
                $tables = array_values(array_unique(array_map(
                    static fn (string $t) => $t === 'employee_attendance' ? 'employee_attendance' : $t,
                    $tables,
                )));
                if (in_array('employee_attendance', $tables, true)) {
                    $tables[] = 'employee_clock_sessions';
                }
            }
            $optimized = $pruner->optimizeRetentionTables(
                $tables,
                function (array $payload): void {
                    $message = (string) ($payload['message'] ?? '');
                    if ($message !== '') {
                        $this->line($message);
                    }
                },
            );
            if ($optimized === []) {
                $this->warn('No tables were optimized.');
            }
        }

        $this->info($dryRun
            ? 'Dry run complete — no rows were deleted.'
            : 'Operational data prune complete.');

        return self::SUCCESS;
    }
}
