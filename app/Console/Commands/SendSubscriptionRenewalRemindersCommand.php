<?php

namespace App\Console\Commands;

use App\Services\Platform\SubscriptionRenewalReminderService;
use Illuminate\Console\Command;

class SendSubscriptionRenewalRemindersCommand extends Command
{
    protected $signature = 'erp:send-subscription-renewal-reminders';

    protected $description = 'Email org admins with renewal invoices when subscriptions are about to expire';

    public function handle(SubscriptionRenewalReminderService $reminders): int
    {
        $result = $reminders->processDueReminders();

        foreach ($result['errors'] as $error) {
            $this->warn($error);
        }

        $this->info(sprintf(
            'Subscription renewal reminders: sent=%d skipped=%d errors=%d',
            $result['sent'],
            $result['skipped'],
            count($result['errors']),
        ));

        return count($result['errors']) > 0 && $result['sent'] === 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
