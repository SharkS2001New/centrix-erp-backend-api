<?php

namespace App\Console\Commands;

use App\Services\Hospitality\HospitalityPosEmailReportService;
use Illuminate\Console\Command;

class SendHospitalityPosEmailReportsCommand extends Command
{
    protected $signature = 'erp:send-hospitality-pos-email-reports';

    protected $description = 'Send Hotel POS hourly/daily maths emails to configured recipients';

    public function handle(HospitalityPosEmailReportService $service): int
    {
        $stats = $service->runDue();
        $this->info(sprintf(
            'Hospitality POS emails: checked %d orgs, hourly=%d, daily=%d, skipped=%d, errors=%d',
            $stats['orgs_checked'],
            $stats['hourly_sent'],
            $stats['daily_sent'],
            $stats['skipped'],
            $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
