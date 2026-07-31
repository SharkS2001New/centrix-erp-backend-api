<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs scheduled Stock Pulse / Sales Brief digests for organizations.
 */
class AiInsightScheduler
{
    public function __construct(
        protected AiInsightService $insights,
        protected AiInsightDeliveryService $delivery,
    ) {}

    /**
     * @return array{orgs_checked: int, stock_pulse: int, sales_brief: int, errors: int}
     */
    public function runDue(?string $nowTime = null): array
    {
        $nowTime = $nowTime ?? now()->format('H:i');
        $stats = ['orgs_checked' => 0, 'stock_pulse' => 0, 'sales_brief' => 0, 'errors' => 0];

        Organization::query()
            ->whereNotNull('module_settings')
            ->orderBy('id')
            ->chunkById(50, function ($orgs) use (&$stats, $nowTime) {
                foreach ($orgs as $organization) {
                    $stats['orgs_checked']++;
                    if (! AiSettingsResolver::insightsEnabled($organization)) {
                        continue;
                    }
                    $settings = AiSettingsResolver::insightsForOrganization($organization);
                    $actor = $this->actorFor($organization);
                    if (! $actor) {
                        continue;
                    }

                    $stock = $settings['stock_pulse'] ?? [];
                    if (! empty($stock['enabled']) && ($stock['schedule_time'] ?? '') === $nowTime) {
                        if ($this->alreadySentToday($organization, 'stock_pulse')) {
                            continue;
                        }
                        try {
                            $insight = $this->insights->stockPulse($actor, $organization);
                            $this->delivery->deliver($organization, $insight);
                            $this->markSentToday($organization, 'stock_pulse');
                            $stats['stock_pulse']++;
                        } catch (\Throwable $e) {
                            $stats['errors']++;
                            Log::warning('AI stock pulse digest failed', [
                                'organization_id' => $organization->id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    $sales = $settings['sales_brief'] ?? [];
                    if (! empty($sales['enabled']) && ($sales['schedule_time'] ?? '') === $nowTime) {
                        if ($this->alreadySentToday($organization, 'sales_brief')) {
                            continue;
                        }
                        try {
                            $insight = $this->insights->salesBrief($actor, $organization);
                            $this->delivery->deliver($organization, $insight);
                            $this->markSentToday($organization, 'sales_brief');
                            $stats['sales_brief']++;
                        } catch (\Throwable $e) {
                            $stats['errors']++;
                            Log::warning('AI sales brief digest failed', [
                                'organization_id' => $organization->id,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            });

        return $stats;
    }

    protected function actorFor(Organization $organization): ?User
    {
        return User::query()
            ->where('organization_id', $organization->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_admin', true)->orWhere('is_super_admin', true);
            })
            ->orderByDesc('is_super_admin')
            ->orderByDesc('is_admin')
            ->orderBy('id')
            ->first();
    }

    protected function alreadySentToday(Organization $organization, string $type): bool
    {
        return Cache::has($this->sentKey($organization, $type));
    }

    protected function markSentToday(Organization $organization, string $type): void
    {
        Cache::put($this->sentKey($organization, $type), true, now()->endOfDay());
    }

    protected function sentKey(Organization $organization, string $type): string
    {
        return 'ai_insight_digest_sent:'.$organization->id.':'.$type.':'.now()->toDateString();
    }
}
