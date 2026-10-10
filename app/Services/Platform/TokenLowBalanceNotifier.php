<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformLicenceBalance;
use App\Models\User;
use App\Services\Notifications\AfricasTalkingSmsService;
use App\Services\Notifications\NotificationSettingsResolver;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Email and SMS the organisation admin when the token balance crosses a published low-balance line.
 * Each line is sent once, until the balance rises above it again.
 */
class TokenLowBalanceNotifier
{
    public function __construct(
        protected BillingPolicy $policy,
        protected AfricasTalkingSmsService $sms,
    ) {}

    public function afterChange(int $organizationId, int $before, int $after): void
    {
        if ($organizationId <= 0 || $before === $after) {
            return;
        }

        try {
            $this->consider($organizationId, $after);
        } catch (\Throwable $e) {
            Log::warning('Centrix token low-balance notice failed', [
                'organization_id' => $organizationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 0 = above every warning line, 1 = first low line, 2 = lower urgent line.
     */
    public static function levelFor(int $balance, int $pack, int $firstPercent, int $secondPercent): int
    {
        $pack = max(1, $pack);
        $lines = [];
        foreach ([$firstPercent, $secondPercent] as $percent) {
            $percent = min(100, max(0, $percent));
            if ($percent > 0) {
                $lines[] = (int) floor($pack * $percent / 100);
            }
        }
        $lines = array_values(array_unique($lines));
        sort($lines);
        if ($lines === []) {
            return 0;
        }
        if (count($lines) === 1) {
            return $balance <= $lines[0] ? 1 : 0;
        }
        if ($balance <= $lines[0]) {
            return 2;
        }
        if ($balance <= $lines[1]) {
            return 1;
        }

        return 0;
    }

    protected function consider(int $organizationId, int $after): void
    {
        if (! Schema::hasTable('platform_licence_balances')
            || ! Schema::hasColumn('platform_licence_balances', 'token_alert_level')) {
            return;
        }

        $row = PlatformLicenceBalance::query()->where('organization_id', $organizationId)->first();
        $org = Organization::query()->find($organizationId);
        if (! $row || ! $org) {
            return;
        }

        $rules = $this->policy->current();
        $level = self::levelFor(
            $after,
            (int) ($rules['token_pack_tokens'] ?? 1),
            (int) ($rules['low_balance_first_percent'] ?? 0),
            (int) ($rules['low_balance_second_percent'] ?? 0),
        );
        $stored = (int) ($row->token_alert_level ?? 0);

        if ($level > $stored && $level > 0) {
            $this->deliver($org, $after, $level);
        }

        if ($level !== $stored) {
            $row->token_alert_level = $level;
            $row->save();
        }
    }

    protected function deliver(Organization $org, int $balance, int $level): void
    {
        $name = trim((string) ($org->org_name ?: $org->company_code ?: 'this organisation'));
        $urgent = $level >= 2;
        $subject = $urgent
            ? "Centrix tokens are almost finished for {$name}"
            : "Centrix tokens are low for {$name}";
        $body = $urgent
            ? "{$name} has {$balance} Centrix token".($balance === 1 ? '' : 's')." left. Sales stop saving once the balance is finished. Top up on the Centrix Paybill."
            : "{$name} has {$balance} Centrix token".($balance === 1 ? '' : 's')." left. Top up on the Centrix Paybill before the balance runs out.";

        foreach ($this->emails($org) as $email) {
            try {
                app(PlatformMailboxService::class)->send($email, $subject, $body, null, [
                    'kind' => 'token_low_balance',
                    'organization_id' => $org->id,
                    'token_balance' => $balance,
                    'alert_level' => $level,
                ]);
            } catch (\Throwable $e) {
                Log::warning('Centrix token low-balance email failed', [
                    'organization_id' => $org->id,
                    'to' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $phone = trim((string) ($org->primary_tel ?? ''));
        if ($phone === '') {
            return;
        }

        $sender = $this->smsSender($org);
        if (! $sender) {
            return;
        }

        $this->sms->send($sender, $phone, $body);
    }

    /** @return list<string> */
    protected function emails(Organization $org): array
    {
        $emails = collect();
        $orgEmail = strtolower(trim((string) $org->org_email));
        if (filter_var($orgEmail, FILTER_VALIDATE_EMAIL)) {
            $emails->push($orgEmail);
        }

        $admins = User::query()
            ->where('organization_id', $org->id)
            ->where('is_admin', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);

        return $emails->merge($admins)->unique()->values()->all();
    }

    protected function smsSender(Organization $org): ?Organization
    {
        $own = NotificationSettingsResolver::forOrganization($org);
        if (! empty($own['sms_enabled'])) {
            return $org;
        }

        $platform = PlatformMailSettingsResolver::platformOrganization();
        if (! $platform || (int) $platform->id === (int) $org->id) {
            return null;
        }

        $platformSettings = NotificationSettingsResolver::forOrganization($platform);

        return ! empty($platformSettings['sms_enabled']) ? $platform : null;
    }
}
