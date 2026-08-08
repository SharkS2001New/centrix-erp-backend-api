<?php

namespace App\Services\Notifications;

use App\Models\Organization;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class DebtReminderService
{
    public const META_SENT_AT = 'debt_reminder_sent_at';

    public function __construct(protected CustomerNotificationService $notifications) {}

    /**
     * @return array{sent: int, skipped: int, errors: list<string>}
     */
    public function processDueReminders(): array
    {
        $sent = 0;
        $skipped = 0;
        $errors = [];

        $orgs = Organization::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($orgs as $organization) {
            try {
                $result = $this->processOrganization($organization);
                $sent += $result['sent'];
                $skipped += $result['skipped'];
                foreach ($result['errors'] as $error) {
                    $errors[] = $error;
                }
            } catch (\Throwable $e) {
                $errors[] = "Org {$organization->id}: {$e->getMessage()}";
                Log::warning('Debt reminder org failed', [
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    /**
     * @return array{sent: int, skipped: int, errors: list<string>}
     */
    public function processOrganization(Organization $organization): array
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        $sent = 0;
        $skipped = 0;
        $errors = [];

        if (empty($settings['notify_on_debt_reminder'])) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => []];
        }

        if (empty($settings['sms_enabled']) && empty($settings['email_enabled'])) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => []];
        }

        $days = max(1, min(365, (int) ($settings['debt_reminder_after_days'] ?? 7)));
        $scopes = NotificationSettingsResolver::normalizeScopes(
            $settings['debt_reminder_scope'] ?? 'debtors',
            'debtors',
        );
        $cutoff = now()->subDays($days)->endOfDay();

        $candidates = Sale::query()
            ->where('organization_id', $organization->id)
            ->whereNotNull('customer_num')
            ->whereNotIn('status', ['cancelled', 'expired', 'draft', 'held'])
            ->whereRaw('(COALESCE(order_total, 0) - COALESCE(amount_paid, 0)) > 0.01')
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('completed_at')->where('completed_at', '<=', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('completed_at')->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->limit(500)
            ->get();

        foreach ($candidates as $sale) {
            $balance = round(max(0, (float) $sale->order_total - (float) $sale->amount_paid), 2);
            if ($balance <= 0.01) {
                $skipped++;
                continue;
            }

            if (! $this->notifications->saleMatchesNotificationScopes($sale, $scopes)) {
                $skipped++;
                continue;
            }

            if (! $this->isDueForReminder($sale, $days)) {
                $skipped++;
                continue;
            }

            try {
                if ($this->notifications->notifyDebtReminder($sale, $organization)) {
                    $this->markReminderSent($sale);
                    $sent++;
                } else {
                    $skipped++;
                }
            } catch (\Throwable $e) {
                $errors[] = "Sale {$sale->id}: {$e->getMessage()}";
                Log::warning('Debt reminder send failed', [
                    'sale_id' => $sale->id,
                    'organization_id' => $organization->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return compact('sent', 'skipped', 'errors');
    }

    protected function isDueForReminder(Sale $sale, int $days): bool
    {
        $age = $this->notifications->unpaidAgeDays($sale);
        if ($age < $days) {
            return false;
        }

        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $sentAt = $meta[self::META_SENT_AT] ?? null;
        if (! $sentAt) {
            return true;
        }

        try {
            $last = Carbon::parse($sentAt);
        } catch (\Throwable) {
            return true;
        }

        // Re-remind every N days while still unpaid.
        return $last->lte(now()->subDays($days)->endOfDay());
    }

    protected function markReminderSent(Sale $sale): void
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];
        $meta[self::META_SENT_AT] = now()->toIso8601String();
        $meta['debt_reminder_count'] = (int) ($meta['debt_reminder_count'] ?? 0) + 1;
        $sale->forceFill(['fulfillment_meta' => $meta])->save();
    }
}
