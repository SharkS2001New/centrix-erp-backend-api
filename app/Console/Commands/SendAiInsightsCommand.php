<?php

namespace App\Console\Commands;

use App\Services\Ai\AiInsightScheduler;
use Illuminate\Console\Command;

class SendAiInsightsCommand extends Command
{
    protected $signature = 'erp:send-ai-insights {--time= : Override HH:MM for due checks}';

    protected $description = 'Send scheduled Centrix AI Stock Pulse and Sales Brief digests';

    public function handle(AiInsightScheduler $scheduler): int
    {
        $time = $this->option('time');
        $stats = $scheduler->runDue(is_string($time) && $time !== '' ? $time : null);
        $this->info(sprintf(
            'AI insights: checked %d orgs, stock_pulse=%d, sales_brief=%d, errors=%d',
            $stats['orgs_checked'],
            $stats['stock_pulse'],
            $stats['sales_brief'],
            $stats['errors'],
        ));

        return self::SUCCESS;
    }
}
