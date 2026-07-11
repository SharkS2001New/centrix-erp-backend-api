<?php

namespace App\Services\Platform;

use App\Models\PlatformMailMessage;
use Carbon\Carbon;
use Illuminate\Support\Str;

class PlatformMailStats
{
    public const KIND_TWO_FACTOR = 'two_factor';

    public const KIND_EMAIL_VERIFICATION = 'email_verification';

    public const KIND_RENEWAL_REMINDER = 'subscription_renewal_reminder';

    public const KIND_RENEWAL_REMINDER_TEST = 'subscription_renewal_reminder_test';

    /**
     * @return array{
     *     two_factor: array{all_time: int, last_7_days: int, last_30_days: int},
     *     email_verification: array{all_time: int, last_7_days: int, last_30_days: int},
     *     auth_codes: array{all_time: int, last_7_days: int, last_30_days: int},
     *     renewal_reminders: array{all_time: int, last_7_days: int, last_30_days: int},
     *     renewal_reminder_tests: array{all_time: int, last_7_days: int, last_30_days: int},
     * }
     */
    public static function summarize(): array
    {
        $twoFactor = self::countsForKind(self::KIND_TWO_FACTOR);
        $verification = self::countsForKind(self::KIND_EMAIL_VERIFICATION);
        $renewals = self::countsForKind(self::KIND_RENEWAL_REMINDER);
        $renewalTests = self::countsForKind(self::KIND_RENEWAL_REMINDER_TEST);

        return [
            'two_factor' => $twoFactor,
            'email_verification' => $verification,
            'auth_codes' => [
                'all_time' => $twoFactor['all_time'] + $verification['all_time'],
                'last_7_days' => $twoFactor['last_7_days'] + $verification['last_7_days'],
                'last_30_days' => $twoFactor['last_30_days'] + $verification['last_30_days'],
            ],
            'renewal_reminders' => $renewals,
            'renewal_reminder_tests' => $renewalTests,
        ];
    }

    /**
     * @return array{all_time: int, last_7_days: int, last_30_days: int}
     */
    public static function countsForKind(string $kind): array
    {
        $base = PlatformMailMessage::query()
            ->where('folder', 'sent')
            ->where('direction', 'outbound')
            ->where('meta->kind', $kind);

        return [
            'all_time' => (clone $base)->count(),
            'last_7_days' => (clone $base)->where('sent_at', '>=', Carbon::now()->subDays(7))->count(),
            'last_30_days' => (clone $base)->where('sent_at', '>=', Carbon::now()->subDays(30))->count(),
        ];
    }

    public static function labelForKind(?string $kind): ?string
    {
        if ($kind === null || $kind === '') {
            return null;
        }

        return match ($kind) {
            self::KIND_TWO_FACTOR => '2FA code',
            self::KIND_EMAIL_VERIFICATION => 'Email verification',
            self::KIND_RENEWAL_REMINDER => 'Renewal reminder',
            self::KIND_RENEWAL_REMINDER_TEST => 'Renewal test',
            'test' => 'Test email',
            'invoice' => 'Invoice',
            'contract' => 'Contract',
            default => Str::headline($kind),
        };
    }
}
