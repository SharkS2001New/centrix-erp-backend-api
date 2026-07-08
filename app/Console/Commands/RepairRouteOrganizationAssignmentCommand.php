<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Organization\OrganizationTenantDataBackfillService;
use App\Services\Organization\RouteOrganizationRepairService;
use Illuminate\Console\Command;

class RepairRouteOrganizationAssignmentCommand extends Command
{
    protected $signature = 'erp:repair-route-organizations
                            {--letter-org=3 : Organization id for single-letter routes A–E}
                            {--default-org=4 : Organization id for all other routes}
                            {--dry-run : Preview changes without writing}
                            {--force : Apply without confirmation prompt}
                            {--skip-head-office : Do not set route customers to head office branch}';

    protected $description = 'Assign routes to organizations (A–E to letter org, others to default org) and reconcile related rows';

    public function handle(
        RouteOrganizationRepairService $repair,
        OrganizationTenantDataBackfillService $backfill,
    ): int {
        $letterOrgId = (int) $this->option('letter-org');
        $defaultOrgId = (int) $this->option('default-org');

        foreach ([$letterOrgId => 'letter', $defaultOrgId => 'default'] as $orgId => $label) {
            $org = Organization::query()->find($orgId);
            if (! $org) {
                $this->error("Organization #{$orgId} ({$label}) not found.");

                return self::FAILURE;
            }
            $this->line("{$label} org: #{$orgId} — {$org->org_name}");
        }

        $preview = $repair->preview($letterOrgId, $defaultOrgId);
        $this->info("Routes: {$preview['total_routes']} total, {$preview['already_correct']} already correct.");
        $this->line("  Letter routes (A–E) to move: {$preview['letter_routes_to_move']}");
        $this->line("  Other routes to move: {$preview['other_routes_to_move']}");

        if ($preview['name_conflicts'] !== []) {
            $this->warn('Name conflicts (will merge into existing route on target org):');
            foreach ($preview['name_conflicts'] as $line) {
                $this->line("  {$line}");
            }
        }

        if ($this->option('dry-run')) {
            if ($preview['letter_routes'] !== []) {
                $this->line('Letter routes:');
                foreach ($preview['letter_routes'] as $row) {
                    $this->line("  #{$row['id']} «{$row['name']}» org {$row['from_org']} → {$row['to_org']}");
                }
            }
            if ($preview['other_routes'] !== []) {
                $this->line('Other routes:');
                foreach ($preview['other_routes'] as $row) {
                    $this->line("  #{$row['id']} «{$row['name']}» org {$row['from_org']} → {$row['to_org']}");
                }
            }

            $this->info('Dry run complete — no changes written.');

            return self::SUCCESS;
        }

        if ($preview['letter_routes_to_move'] === 0 && $preview['other_routes_to_move'] === 0) {
            $this->info('All routes already on the correct organization.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply route organization repair?', true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $stats = $repair->run(
            $letterOrgId,
            $defaultOrgId,
            ! $this->option('skip-head-office'),
        );

        $this->info('Route repair complete:');
        foreach ($stats as $step => $count) {
            if ($count > 0) {
                $this->line("  {$step}: {$count}");
            }
        }

        $tenantStats = $backfill->run();
        $tenantTotal = array_sum($tenantStats);
        if ($tenantTotal > 0) {
            $this->info('Tenant organization_id backfill:');
            foreach ($tenantStats as $step => $count) {
                if ($count > 0) {
                    $this->line("  {$step}: {$count}");
                }
            }
        }

        return self::SUCCESS;
    }
}
