<?php

namespace App\Services\Ai;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Runs scheduled AI insight digests for organizations.
 */
class AiInsightScheduler
{
    public function __construct(
        protected AiInsightService $insights,
        protected AiInsightDeliveryService $delivery,
    ) {}

    /**
     * @return array<string, int>
     */
    public function runDue(?string $nowTime = null): array
    {
        $nowTime = $nowTime ?? now()->format('H:i');
        $stats = ['orgs_checked' => 0, 'errors' => 0];
        foreach (AiInsightCatalog::scheduledTypes() as $type) {
            $stats[$type] = 0;
        }

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

                    foreach (AiInsightCatalog::scheduledTypes() as $type) {
                        $cfg = $settings[$type] ?? [];
                        if (empty($cfg['enabled']) || ($cfg['schedule_time'] ?? '') !== $nowTime) {
                            continue;
                        }
                        if ($this->alreadySentToday($organization, $type)) {
                            continue;
                        }
                        try {
                            $insight = $this->insights->runType($actor, $organization, $type);
                            $this->delivery->deliver($organization, $insight);
                            $this->markSentToday($organization, $type);
                            $stats[$type] = ($stats[$type] ?? 0) + 1;
                        } catch (\Throwable $e) {
                            $stats['errors']++;
                            Log::warning('AI insight digest failed', [
                                'organization_id' => $organization->id,
                                'type' => $type,
                                'message' => $e->getMessage(),
                            ]);
                        }
                    }

                    // Wired exception radar when exception_alerts.enabled (same morning window).
                    $alerts = $settings['exception_alerts'] ?? [];
                    if (! empty($alerts['enabled']) && ($settings['exception_radar']['schedule_time'] ?? '07:05') === $nowTime) {
                        if (! $this->alreadySentToday($organization, 'exception_radar_alerts')) {
                            try {
                                $insight = $this->insights->runType($actor, $organization, 'exception_radar');
                                $this->delivery->deliver($organization, $insight);
                                $this->markSentToday($organization, 'exception_radar_alerts');
                                $stats['exception_radar'] = ($stats['exception_radar'] ?? 0) + 1;
                            } catch (\Throwable $e) {
                                $stats['errors']++;
                                Log::warning('AI exception radar failed', [
                                    'organization_id' => $organization->id,
                                    'message' => $e->getMessage(),
                                ]);
                            }
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
