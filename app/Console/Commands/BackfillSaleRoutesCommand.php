<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Sales\SaleRouteBackfillService;
use Illuminate\Console\Command;

class BackfillSaleRoutesCommand extends Command
{
    protected $signature = 'erp:backfill-sale-routes
                            {--organization= : Limit backfill to one organization id}';

    protected $description = 'Backfill sales.route_id from customer route assignments (background maintenance)';

    public function handle(SaleRouteBackfillService $service): int
    {
        $orgArg = $this->option('organization');
        $onlyOrganizationId = filled($orgArg) ? (int) $orgArg : null;

        if ($onlyOrganizationId !== null) {
            $organization = Organization::query()->find($onlyOrganizationId);
            if (! $organization) {
                $this->error("Organization #{$onlyOrganizationId} not found.");

                return self::FAILURE;
            }

            $updated = $service->syncOrganization($organization);
            $this->info("Updated {$updated} sale(s) for organization #{$onlyOrganizationId}.");

            return self::SUCCESS;
        }

        $updated = 0;
        Organization::query()
            ->orderBy('id')
            ->chunkById(50, function ($organizations) use ($service, &$updated) {
                foreach ($organizations as $organization) {
                    $updated += $service->syncOrganization($organization);
                }
            });

        $this->info("Updated {$updated} sale(s) across all organizations.");

        return self::SUCCESS;
    }
}
