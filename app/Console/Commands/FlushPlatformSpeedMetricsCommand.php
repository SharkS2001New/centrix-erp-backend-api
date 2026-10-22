<?php

namespace App\Console\Commands;

use App\Services\Platform\Speed\PlatformInfraSnapshot;
use App\Services\Platform\Speed\PlatformSpeedMetricsFlusher;
use Illuminate\Console\Command;

class FlushPlatformSpeedMetricsCommand extends Command
{
    protected $signature = 'erp:flush-speed-metrics';

    protected $description = 'Flush ERP Speed Redis/cache aggregates into MySQL minute tables and sample infra.';

    public function handle(PlatformSpeedMetricsFlusher $flusher, PlatformInfraSnapshot $infra): int
    {
        $result = $flusher->flush();
        $infra->sampleToDatabase();
        $this->info('Flushed API keys: '.$result['api'].', query keys: '.$result['queries']);

        return self::SUCCESS;
    }
}
