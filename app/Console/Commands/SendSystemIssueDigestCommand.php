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
        $recipient = $this->option('email') ?: config('system_issues.digest_email');

        if (trim((string) $recipient) === '') {
            $this->warn('Set SYSTEM_ISSUES_DIGEST_EMAIL to receive the daily digest.');

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
