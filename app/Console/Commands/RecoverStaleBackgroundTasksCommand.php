<?php

namespace App\Console\Commands;

use App\Services\Background\BackgroundTaskService;
use Illuminate\Console\Command;

class RecoverStaleBackgroundTasksCommand extends Command
{
    protected $signature = 'erp:recover-stale-background-tasks';

    protected $description = 'Mark abandoned pending/running background tasks as failed';

    public function handle(BackgroundTaskService $tasks): int
    {
        $count = $tasks->recoverStaleTasks();

        $this->info("Recovered {$count} stale background task(s).");

        return self::SUCCESS;
    }
}
