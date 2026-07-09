<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Cache\CompletedSalesCacheService;
use Illuminate\Console\Command;

class WarmCompletedSalesCacheCommand extends Command
{
    protected $signature = 'erp:warm-completed-sales-cache
                            {--organization= : Limit warmup to one organization id}
                            {--days= : Override config warm_days}';

    protected $description = 'Pre-warm immutable completed sales cache for web and mobile clients';

    public function handle(CompletedSalesCacheService $cache): int
    {
        if (! $cache->enabled()) {
            $this->warn('Completed sales cache is disabled (COMPLETED_SALES_CACHE_ENABLED=false).');

            return self::SUCCESS;
        }

        $orgArg = $this->option('organization');
        $onlyOrganizationId = filled($orgArg) ? (int) $orgArg : null;
        $daysOverride = $this->option('days');
        if ($daysOverride !== null && $daysOverride !== '') {
            config(['completed_sales_cache.warm_days' => max(1, (int) $daysOverride)]);
        }

        $warmed = 0;

        $query = Organization::query()->orderBy('id');
        if ($onlyOrganizationId !== null) {
            $query->whereKey($onlyOrganizationId);
        }

        $organizations = $query->get();
        if ($organizations->isEmpty()) {
            $this->error('No organizations matched the warmup scope.');

            return self::FAILURE;
        }

        foreach ($organizations as $organization) {
            $count = $cache->warmOrganization($organization);
            $warmed += $count;
            $this->line("Organization #{$organization->id}: warmed {$count} completed sale(s).");
        }

        $this->info("Warmup finished — {$warmed} completed sale(s) across ".$organizations->count().' organization(s).');

        return self::SUCCESS;
    }
}
