<?php

namespace App\Console\Commands;

use App\Services\Notifications\DebtReminderService;
use Illuminate\Console\Command;

class SendDebtRemindersCommand extends Command
{
    protected $signature = 'erp:send-debt-reminders';

    protected $description = 'SMS/email customers with unpaid order balances after the org-configured number of days';

    public function handle(DebtReminderService $reminders): int
    {
        $result = $reminders->processDueReminders();

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        $this->info(sprintf(
            'Debt reminders: sent=%d skipped=%d errors=%d',
            $result['sent'],
            $result['skipped'],
            count($result['errors']),
        ));

        return count($result['errors']) > 0 && $result['sent'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
