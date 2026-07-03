<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Organization\OrganizationTenantDataBackfillService;
use Illuminate\Console\Command;

class BackfillOrganizationTenantDataCommand extends Command
{
    protected $signature = 'tenant:backfill-organization-data
                            {--organization= : Limit backfill to one organization id (e.g. 4)}
                            {--audit : Only report rows with missing or mismatched organization_id}
                            {--dry-run : Alias for --audit}';

    protected $description = 'Backfill organization_id on tenant tables from branches and parent records';

    public function handle(OrganizationTenantDataBackfillService $service): int
    {
        $orgArg = $this->option('organization');
        $onlyOrganizationId = filled($orgArg) ? (int) $orgArg : null;

        if ($onlyOrganizationId !== null) {
            $org = Organization::query()->find($onlyOrganizationId);
            if (! $org) {
                $this->error("Organization #{$onlyOrganizationId} not found.");

                return self::FAILURE;
            }
            $this->info("Scope: organization #{$onlyOrganizationId} ({$org->org_name})");
        } else {
            $this->info('Scope: all organizations');
        }

        if ($this->option('audit') || $this->option('dry-run')) {
            $issues = $service->audit($onlyOrganizationId);
            if ($issues === []) {
                $this->info('No organization_id mismatches found.');

                return self::SUCCESS;
            }

            $this->warn('Rows needing organization_id reconciliation:');
            foreach ($issues as $table => $count) {
                $this->line("  {$table}: {$count}");
            }

            return self::SUCCESS;
        }

        $stats = $service->run($onlyOrganizationId);
        $total = array_sum($stats);

        if ($total === 0) {
            $this->info('No rows updated — organization_id already aligned.');
        } else {
            $this->info('Backfill complete:');
            foreach ($stats as $step => $count) {
                if ($count > 0) {
                    $this->line("  {$step}: {$count}");
                }
            }
            $this->line("  total updated: {$total}");
        }

        $remaining = $service->audit($onlyOrganizationId);
        if ($remaining !== []) {
            $this->warn('Remaining mismatches after backfill:');
            foreach ($remaining as $table => $count) {
                $this->line("  {$table}: {$count}");
            }
        }

        return self::SUCCESS;
    }
}
