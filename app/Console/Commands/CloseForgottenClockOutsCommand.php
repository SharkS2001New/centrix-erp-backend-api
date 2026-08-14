<?php

namespace App\Console\Commands;

use App\Services\Attendance\ForgottenClockOutService;
use Illuminate\Console\Command;

class CloseForgottenClockOutsCommand extends Command
{
    protected $signature = 'erp:close-forgotten-clock-outs
                            {--organization= : Limit to one organization id}';

    protected $description = 'Auto-close yesterday’s open clock sessions at shift end (02:00 Nairobi) and flag them for HR as forgotten clock-outs.';

    public function handle(ForgottenClockOutService $closer): int
    {
        $orgId = $this->option('organization') !== null && $this->option('organization') !== ''
            ? (int) $this->option('organization')
            : null;

        $result = $closer->closeDueSessions($orgId);
        $this->info(sprintf(
            'Auto-closed %d forgotten clock-out(s); skipped %d.',
            $result['closed'],
            $result['skipped'],
        ));
        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        return self::SUCCESS;
    }
}