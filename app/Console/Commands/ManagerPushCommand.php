<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\Mobile\FcmPushNotificationService;
use App\Services\Mobile\FcmPushSettingsResolver;
use App\Services\Mobile\UserDeviceTokenService;
use Illuminate\Console\Command;

class ManagerPushCommand extends Command
{
    protected $signature = 'manager:push
                            {action=status : status|test}
                            {--user-id= : Send test push to all tokens for this user}
                            {--token= : Send test push to a specific FCM device token}
                            {--app=manager : App channel: manager or mobile_sales}';

    protected $description = 'Diagnose Centrix FCM push (Manager + Mobile sales) and send test notifications';

    public function handle(
        FcmPushNotificationService $push,
        UserDeviceTokenService $tokens,
    ): int {
        $action = strtolower((string) $this->argument('action'));

        return match ($action) {
            'status' => $this->runStatus($push),
            'test' => $this->runTest($push, $tokens),
            default => $this->invalidAction($action),
        };
    }

    protected function runStatus(FcmPushNotificationService $push): int
    {
        $report = $push->diagnose();

        $this->info('Centrix FCM push configuration');
        $this->table(
            ['Check', 'Value'],
            [
                ['Push enabled', $report['enabled'] ? 'yes' : 'no'],
                ['FCM project ID', $report['project_id_configured'] ? (string) $report['project_id'] : '(missing)'],
                ['Credentials path', $report['credentials_path'] ?: '(missing)'],
                ['Credentials file exists', $report['credentials_file_exists'] ? 'yes' : 'no'],
                ['OAuth token', $report['oauth_token_ok'] ? 'ok' : 'failed'],
                ['Ignore local tokens', $report['ignore_local_tokens'] ? 'yes' : 'no'],
                ['Ready to send', $report['ready'] ? 'yes' : 'no'],
            ],
        );

        if (is_array($report['apps'] ?? null)) {
            $this->newLine();
            $this->info('App channels');
            foreach ($report['apps'] as $channel => $label) {
                $this->line("  {$channel}: {$label}");
            }
        }

        if ($report['oauth_error']) {
            $this->warn('OAuth error: '.$report['oauth_error']);
        }

        if (! $report['ready']) {
            $this->newLine();
            $this->line('Setup: centrix_manager_app/docs/FIREBASE_SETUP.md');

            return self::FAILURE;
        }

        $this->info('Push delivery is configured correctly.');

        return self::SUCCESS;
    }

    protected function runTest(
        FcmPushNotificationService $push,
        UserDeviceTokenService $tokens,
    ): int {
        $status = $push->diagnose();
        if (! ($status['ready'] ?? false)) {
            $this->error('Push is not ready. Run: php artisan manager:push status');

            return self::FAILURE;
        }

        $appChannel = $this->resolveAppChannel();
        $deviceTokens = $this->resolveTokens($tokens, $appChannel);
        if ($deviceTokens === []) {
            $this->error("No device token found for app \"{$appChannel}\". Pass --token=... or --user-id=... after the app registers FCM.");

            return self::FAILURE;
        }

        $dataType = $appChannel === UserDeviceToken::CHANNEL_MOBILE_SALES
            ? 'approval_outcome'
            : 'approval';

        $failures = 0;
        foreach ($deviceTokens as $token) {
            $masked = strlen($token) > 12
                ? substr($token, 0, 8).'…'.substr($token, -4)
                : $token;
            $this->line("Sending test push ({$appChannel}) to {$masked}…");

            $result = $push->sendTest($token, dataType: $dataType);
            if ($result['ok'] ?? false) {
                $this->info('  Delivered.');
            } else {
                $failures++;
                $this->error('  Failed: '.json_encode($result['body'] ?? $result['message'] ?? $result));
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    protected function resolveAppChannel(): string
    {
        $app = strtolower(trim((string) $this->option('app')));

        return match ($app) {
            'mobile', 'mobile_sales', 'sales' => UserDeviceToken::CHANNEL_MOBILE_SALES,
            default => UserDeviceToken::CHANNEL_MANAGER,
        };
    }

    /** @return list<string> */
    protected function resolveTokens(UserDeviceTokenService $tokens, string $appChannel): array
    {
        if ($token = trim((string) $this->option('token'))) {
            return [$token];
        }

        $userId = (int) $this->option('user-id');
        if ($userId <= 0) {
            return [];
        }

        $user = User::query()->find($userId);
        if (! $user) {
            $this->error("User {$userId} not found.");

            return [];
        }

        $list = $tokens->tokensForUser($user, $appChannel);
        if (FcmPushSettingsResolver::resolve()['ignore_local_tokens']) {
            $list = array_values(array_filter(
                $list,
                fn (string $token) => ! str_starts_with($token, 'mgr-local-')
                    && ! str_starts_with($token, 'mob-local-'),
            ));
        }

        return $list;
    }

    protected function invalidAction(string $action): int
    {
        $this->error("Unknown action \"{$action}\". Use: status or test");

        return self::FAILURE;
    }
}
