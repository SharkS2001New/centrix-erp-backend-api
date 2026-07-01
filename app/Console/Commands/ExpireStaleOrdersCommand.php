<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Sales\OrderExpiryService;
use Illuminate\Console\Command;

class ExpireStaleOrdersCommand extends Command
{
    protected $signature = 'erp:expire-stale-orders {--organization= : Limit to one organization id}';

    protected $description = 'Move stale pipeline orders to expired status per organization settings';

    public function handle(OrderExpiryService $expiry): int
    {
        $orgId = $this->option('organization');
        $query = Organization::query()->where('is_active', true);

        if ($orgId) {
            $query->where('id', (int) $orgId);
        }

        $total = 0;
        $query->orderBy('id')->each(function (Organization $organization) use ($expiry, &$total) {
            $count = $expiry->expireStaleOrdersForOrganization($organization);
            if ($count > 0) {
                $this->line("Organization {$organization->company_code}: expired {$count} order(s).");
            }
            $total += $count;
        });

        $this->info("Expired {$total} stale order(s).");

        return self::SUCCESS;
    }
}
