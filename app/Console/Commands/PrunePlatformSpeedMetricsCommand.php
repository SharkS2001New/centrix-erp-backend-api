<?php

namespace App\Console\Commands;

use App\Services\Platform\Speed\PlatformSpeedMetricsFlusher;
use Illuminate\Console\Command;

class PrunePlatformSpeedMetricsCommand extends Command
{
    protected $signature = 'erp:prune-speed-metrics';

    protected $description = 'Prune old ERP Speed minute aggregates and synthetic results.';

    public function handle(PlatformSpeedMetricsFlusher $flusher): int
    {
        $deleted = $flusher->prune();
        $this->info("Pruned {$deleted} rows.");

        return self::SUCCESS;
    }
}
