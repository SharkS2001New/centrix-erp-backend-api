<?php

namespace App\Console\Commands;

use App\Services\SystemIssues\SystemIssueDigestService;
use Illuminate\Console\Command;

class SendSystemIssueDigestCommand extends Command
{
    protected $signature = 'erp:send-system-issue-digest {--email= : Override digest recipient}';

    protected $description = 'Email daily system issues digest to the platform operator';

    public function handle(SystemIssueDigestService $digest): int
    {
        $settings = \App\Services\SystemIssues\SystemIssueAlertSettingsResolver::forPlatform();
        if (! ($settings['email_digest_enabled'] ?? true) && ! $this->option('email')) {
            $this->info('Daily email digest is disabled in Platform → System errors & reports.');

            return self::SUCCESS;
        }

        $recipient = $this->option('email')
            ?: \App\Services\SystemIssues\SystemIssueAlertSettingsResolver::digestEmail();

        if (trim((string) $recipient) === '') {
            $this->warn('Set a digest email under Platform → System errors & reports (or SYSTEM_ISSUES_DIGEST_EMAIL).');

            return self::SUCCESS;
        }

        try {
            $digest->sendDailyDigest($recipient);
        } catch (\Throwable $e) {
            $this->error('Failed to send system issue digest: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('System issue digest sent to '.$recipient);

        return self::SUCCESS;
    }
}
