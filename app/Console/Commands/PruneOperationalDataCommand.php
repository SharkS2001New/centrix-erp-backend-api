<?php

namespace App\Console\Commands;

use App\Services\Retention\OperationalDataPruneService;
use Illuminate\Console\Command;

class PruneOperationalDataCommand extends Command
{
    protected $signature = 'erp:prune-operational-data
                            {--dry-run : Count matching rows without deleting}';

    protected $description = 'Prune stock holds, agent commands, attendance older than retention, audit logs, and terminal sales';

    public function handle(OperationalDataPruneService $pruner): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $results = $pruner->pruneAll($dryRun);

        $verb = $dryRun ? 'Would delete' : 'Deleted';
        foreach ($results as $key => $count) {
            $this->line("{$verb} {$count} {$key}");
        }

        $this->info($dryRun
            ? 'Dry run complete — no rows were deleted.'
            : 'Operational data prune complete.');

        return self::SUCCESS;
    }
}
