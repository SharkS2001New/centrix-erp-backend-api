<?php

namespace App\Console\Commands;

use App\Services\Platform\Speed\PlatformSpeedSyntheticRunner;
use Illuminate\Console\Command;

class RunPlatformSpeedSyntheticCommand extends Command
{
    protected $signature = 'erp:run-speed-synthetic';

    protected $description = 'Run due ERP Speed synthetic endpoint checks (safe read-only).';

    public function handle(PlatformSpeedSyntheticRunner $runner): int
    {
        $result = $runner->runDue();
        $this->info('Ran '.$result['ran'].' checks ('.$result['failed'].' failed).');

        return self::SUCCESS;
    }
}
