<?php

namespace App\Console\Commands;

use App\Services\Legacy\LightStoresLegacyImporter;
use Illuminate\Console\Command;

class ImportLightStoresLegacyCommand extends Command
{
    protected $signature = 'legacy:import-lightstores
                            {--dry-run : Count legacy rows without writing to Centrix}
                            {--force : Allow import when the target organization already exists}
                            {--only= : Comma-separated phases: foundation,catalog,customers,sales}';

    protected $description = 'Import LightStores legacy data from LEGACY_DB_* into the Centrix database';

    public function handle(LightStoresLegacyImporter $importer): int
    {
        $only = $this->option('only')
            ? array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))))
            : null;

        $this->line('Legacy source: '.config('database.connections.legacy.database'));
        $this->line('Centrix target: '.config('database.connections.'.config('database.default').'.database'));

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no data will be written.');
        }

        try {
            $stats = $importer->run(
                dryRun: (bool) $this->option('dry-run'),
                only: $only,
                force: (bool) $this->option('force'),
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
            $this->line('Legacy sales use prefixed labels (R01 POS, M01 mobile, D01 debtor) in fulfillment_meta. Live sales continue from order_num 1.');
        }

        return self::SUCCESS;
    }
}
