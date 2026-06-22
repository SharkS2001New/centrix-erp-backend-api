<?php

namespace App\Console\Commands;

use App\Services\Legacy\LightStoresLegacyImporter;
use App\Models\Organization;
use App\Services\Legacy\LegacyArchiveConnectionManager;
use Illuminate\Console\Command;

class ImportLightStoresLegacyCommand extends Command
{
    protected $signature = 'legacy:import-lightstores
                            {--dry-run : Count legacy rows without writing to Centrix}
                            {--force : Allow import when the target organization already exists}
                            {--master-data : Import master data into Centrix (VAT, UOMs, suppliers, products, retail packages, customers, routes). Sales stay in the legacy archive database}
                            {--organization= : Centrix organization id — uses that tenant legacy-archive database settings}
                            {--only= : Comma-separated phases: foundation,catalog,customers,sales}';

    protected $description = 'Import LightStores master data into Centrix; legacy MySQL remains a read-only sales archive';

    public function handle(LightStoresLegacyImporter $importer): int
    {
        $only = $this->option('only')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))))
            : null;

        if ($this->option('master-data')) {
            $only = LightStoresLegacyImporter::MASTER_DATA_PHASES;
        }

        $legacyDatabase = $this->resolveLegacyDatabaseName(
            $this->option('organization') ? (int) $this->option('organization') : null,
        );
        $this->line('Legacy source: '.$legacyDatabase);
        $this->line('Centrix target: '.config('database.connections.'.config('database.default').'.database'));

        if ($this->option('master-data') || ($only !== null && ! in_array('sales', $only, true))) {
            $this->comment('Master data → Centrix: VAT, UOMs, suppliers, products, retail packages, customers, routes.');
            $this->comment('Historical sales → legacy archive only (browse/materialize via /reports/legacy-archive).');
        } elseif ($only === null) {
            $this->warn('No --master-data flag: bulk sales import will copy historical sales into Centrix (not recommended when legacy archive is enabled).');
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no data will be written.');
        }

        try {
            $stats = $importer->run(
                dryRun: (bool) $this->option('dry-run'),
                only: $only,
                force: (bool) $this->option('force'),
                organizationId: $this->option('organization') ? (int) $this->option('organization') : null,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($stats === []) {
            $this->warn('No rows counted. Check --only filter or legacy database contents.');

            return self::SUCCESS;
        }

        $this->table(['Entity', 'Rows'], collect($stats)->map(fn ($count, $entity) => [$entity, $count])->values()->all());

        if ($this->option('dry-run')) {
            $this->comment('Re-run without --dry-run after restoring legacy SQL and migrating Centrix.');
        } else {
            $this->info('Legacy import completed.');
            $this->line('Users imported with new random passwords — reset before login.');
            $this->line('Stock was not imported; run a fresh stocktake in Centrix.');
            if ($only === null || in_array('sales', $only, true)) {
                $this->line('Legacy sales use prefixed labels (R01 POS, M01 mobile, D01 debtor) in fulfillment_meta. Live sales continue from order_num 1.');
            } else {
                $this->info('Master data is now in Centrix (products, customers, VAT, UOMs, suppliers, retail packages).');
                $this->line('Legacy database is read-only for historical sales — use legacy archive reports or materialize on demand.');
            }
        }

        return self::SUCCESS;
    }

    protected function resolveLegacyDatabaseName(?int $organizationId): string
    {
        if ($organizationId) {
            $org = Organization::query()->find($organizationId);
            if ($org) {
                $connection = app(LegacyArchiveConnectionManager::class)->configureForOrganization($org);

                return (string) config('database.connections.'.$connection.'.database', '');
            }
        }

        return (string) config('database.connections.legacy.database', '');
    }
}
