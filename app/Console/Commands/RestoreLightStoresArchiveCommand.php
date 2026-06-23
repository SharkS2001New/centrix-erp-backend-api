<?php

namespace App\Console\Commands;

use App\Services\Legacy\LightStoresArchiveDatabaseService;
use App\Services\Legacy\LightStoresLegacySchema;
use App\Services\Legacy\LegacyArchiveConnectionManager;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreLightStoresArchiveCommand extends Command
{
    protected $signature = 'legacy:restore-archive
                            {--database=lightstores_moonlight : Legacy MySQL database name to create/import into}
                            {--sql= : Path to LightStoresDBBackup.sql (default: ~/Documents/LightStoresDBBackup.sql)}
                            {--host= : MySQL host (default: database.connections.legacy)}
                            {--port= : MySQL port}
                            {--username= : MySQL username}
                            {--password= : MySQL password}
                            {--verify-only : Only inspect an existing database; do not import}
                            {--force : Skip confirmation when restoring (drops the database)}
                            {--organization= : After restore, print legacy-archive settings hint for this org id}';

    protected $description = 'Restore LightStores legacy archive DB (sale_masters, sale_products, route_master, debtor_*, etc.) from SQL dump';

    public function handle(
        LightStoresArchiveDatabaseService $archiveDb,
        LegacyArchiveConnectionManager $connections,
    ): int {
        $database = (string) $this->option('database');
        $sqlPath = (string) ($this->option('sql') ?: $this->defaultSqlPath());

        if ($this->option('verify-only')) {
            return $this->verifyDatabase($archiveDb, $database);
        }

        if (! is_file($sqlPath)) {
            $this->error("SQL dump not found: {$sqlPath}");
            $this->line('Pass --sql=/full/path/to/LightStoresDBBackup.sql');

            return self::FAILURE;
        }

        $this->warn("This will DROP and recreate `{$database}` from:");
        $this->line($sqlPath);
        $this->newLine();
        $this->comment('Tables restored (from LightStoresDBBackup.sql):');
        foreach (LightStoresLegacySchema::salesChannelGroups() as $key => $group) {
            $this->line("  {$key}: ".implode(', ', $group['tables']));
        }
        $this->newLine();

        if (! $this->option('force') && ! $this->confirm('Continue?', true)) {
            return self::SUCCESS;
        }

        try {
            $result = $archiveDb->restoreFromSqlFile(
                $database,
                $sqlPath,
                $this->option('host') ?: null,
                $this->option('port') ?: null,
                $this->option('username') ?: null,
                $this->option('password') ?: null,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored `{$result['database']}` in {$result['import_seconds']}s.");
        $this->renderInspectTable($result['inspect']);

        if ($orgId = $this->option('organization')) {
            $this->printOrganizationHint((int) $orgId, $database);
        } else {
            $this->newLine();
            $this->comment('Point the tenant legacy archive at this database (Platform → Organization → Legacy archive).');
            $this->line("  database: {$database}");
        }

        return self::SUCCESS;
    }

    protected function verifyDatabase(LightStoresArchiveDatabaseService $archiveDb, string $database): int
    {
        $connectionName = 'legacy_verify_cli';
        $template = config('database.connections.legacy', config('database.connections.mysql'));
        config(['database.connections.'.$connectionName => array_merge($template, [
            'database' => $database,
            'host' => $this->option('host') ?: ($template['host'] ?? '127.0.0.1'),
            'port' => $this->option('port') ?: ($template['port'] ?? '3306'),
            'username' => $this->option('username') ?: ($template['username'] ?? 'root'),
            'password' => $this->option('password') ?? ($template['password'] ?? ''),
        ])]);
        DB::purge($connectionName);

        try {
            DB::connection($connectionName)->select('SELECT 1');
        } catch (\Throwable $e) {
            $this->error("Cannot reach `{$database}`: ".$e->getMessage());

            return self::FAILURE;
        }

        $inspect = $archiveDb->inspect($connectionName);
        $this->renderInspectTable($inspect);

        if ($inspect['missing'] !== []) {
            $this->error('Missing required tables — run legacy:restore-archive to import the full SQL dump.');

            return self::FAILURE;
        }

        $this->info('Legacy archive schema looks complete.');

        return self::SUCCESS;
    }

    /**
     * @param  array{tables: array<string, array{exists: bool, rows: int|null}>, missing: list<string>, sales_counts: array<string, int|null>}  $inspect
     */
    protected function renderInspectTable(array $inspect): void
    {
        $rows = [];
        foreach (LightStoresLegacySchema::salesChannelGroups() as $key => $group) {
            foreach ($group['tables'] as $table) {
                $meta = $inspect['tables'][$table] ?? ['exists' => false, 'rows' => null];
                $rows[] = [
                    $key,
                    $table,
                    $meta['exists'] ? 'yes' : 'NO',
                    $meta['rows'] ?? '—',
                ];
            }
        }

        $this->table(['Channel', 'Table', 'Exists', 'Rows'], $rows);

        if ($inspect['sales_counts'] ?? []) {
            $this->newLine();
            $this->comment('Sales counts');
            $this->table(
                ['Metric', 'Count'],
                collect($inspect['sales_counts'])->map(fn ($v, $k) => [$k, $v])->values()->all(),
            );
        }
    }

    protected function printOrganizationHint(int $organizationId, string $database): void
    {
        $org = Organization::query()->find($organizationId);
        if (! $org) {
            $this->warn("Organization #{$organizationId} not found.");

            return;
        }

        $this->newLine();
        $this->info("Enable legacy archive for {$org->org_name} (id {$org->id}):");
        $this->line("  PATCH /api/v1/admin/organizations/{$org->id}/settings/legacy-archive");
        $this->line('  { "enabled": true, "database": "'.$database.'", "label": "Moonlight LightStores archive" }');
    }

    protected function defaultSqlPath(): string
    {
        $home = (string) (getenv('HOME') ?: getenv('USERPROFILE') ?: '');

        return $home !== ''
            ? $home.'/Documents/LightStoresDBBackup.sql'
            : '/Documents/LightStoresDBBackup.sql';
    }
}
