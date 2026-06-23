<?php

namespace App\Services\Legacy;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process as SymfonyProcess;

class LightStoresArchiveDatabaseService
{
    /**
     * @return array{
     *   database: string,
     *   reachable: bool,
     *   tables: array<string, array{exists: bool, rows: int|null}>,
     *   missing: list<string>,
     *   sales_counts: array<string, int|null>
     * }
     */
    public function inspect(string $connectionName): array
    {
        $database = (string) config('database.connections.'.$connectionName.'.database', '');
        $schema = DB::connection($connectionName)->getSchemaBuilder();
        $tables = [];
        $missing = [];

        foreach (LightStoresLegacySchema::requiredArchiveTables() as $table) {
            $exists = $schema->hasTable($table);
            $rows = null;
            if ($exists) {
                try {
                    $rows = (int) DB::connection($connectionName)->table($table)->count();
                } catch (\Throwable) {
                    $rows = null;
                }
            } else {
                $missing[] = $table;
            }
            $tables[$table] = ['exists' => $exists, 'rows' => $rows];
        }

        $salesCounts = [];
        if ($missing === []) {
            $salesCounts = $this->salesCounts($connectionName);
        }

        return [
            'database' => $database,
            'reachable' => true,
            'tables' => $tables,
            'missing' => $missing,
            'sales_counts' => $salesCounts,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function salesCounts(string $connectionName): array
    {
        $legacy = DB::connection($connectionName);

        return [
            'sales_pos' => (int) $legacy->table(LightStoresLegacySchema::POS_MASTERS)->count(),
            'sales_pos_lines' => (int) $legacy->table(LightStoresLegacySchema::POS_LINES)->count(),
            'sales_mobile' => (int) $legacy->table(LightStoresLegacySchema::ROUTE_MASTERS)->whereNull('DLT_ON')->count(),
            'sales_mobile_lines' => (int) $legacy->table(LightStoresLegacySchema::ROUTE_LINES)->count(),
            'sales_debtor' => (int) $legacy->table(LightStoresLegacySchema::DEBTOR_MASTERS)->whereNull('dlt_on')->count(),
            'sales_debtor_lines' => (int) $legacy->table(LightStoresLegacySchema::DEBTOR_LINES)->count(),
        ];
    }

    /**
     * Drop and recreate a legacy archive database, then import the LightStores SQL dump.
     *
     * @return array{database: string, import_seconds: float, inspect: array<string, mixed>}
     */
    public function restoreFromSqlFile(
        string $database,
        string $sqlPath,
        ?string $host = null,
        ?string $port = null,
        ?string $username = null,
        ?string $password = null,
    ): array {
        if (! is_file($sqlPath)) {
            throw new RuntimeException("SQL dump not found: {$sqlPath}");
        }

        $template = config('database.connections.legacy', config('database.connections.mysql'));
        $host = $host ?: (string) ($template['host'] ?? '127.0.0.1');
        $port = $port ?: (string) ($template['port'] ?? '3306');
        $username = $username ?: (string) ($template['username'] ?? 'root');
        $password = $password ?? (string) ($template['password'] ?? '');

        $this->runMysqlCli($host, $port, $username, $password, null, [
            'DROP DATABASE IF EXISTS `'.$this->quoteIdentifier($database).'`',
            'CREATE DATABASE `'.$this->quoteIdentifier($database).'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        ]);

        $started = microtime(true);
        $this->importSqlFile($host, $port, $username, $password, $database, $sqlPath);
        $importSeconds = microtime(true) - $started;

        $connectionName = 'legacy_restore_verify';
        config(['database.connections.'.$connectionName => array_merge($template, [
            'database' => $database,
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
        ])]);
        DB::purge($connectionName);

        $inspect = $this->inspect($connectionName);
        if ($inspect['missing'] !== []) {
            throw new RuntimeException(
                'Restore finished but required tables are still missing: '.implode(', ', $inspect['missing']),
            );
        }

        return [
            'database' => $database,
            'import_seconds' => round($importSeconds, 2),
            'inspect' => $inspect,
        ];
    }

    /**
     * @param  list<string>  $statements
     */
    protected function runMysqlCli(
        string $host,
        string $port,
        string $username,
        string $password,
        ?string $database,
        array $statements,
    ): void {
        $args = [
            'mysql',
            '-h', $host,
            '-P', $port,
            '-u', $username,
        ];
        if ($database) {
            $args[] = $database;
        }

        $env = $password !== '' ? ['MYSQL_PWD' => $password] : [];
        $sql = implode(";\n", $statements).';';

        $process = new SymfonyProcess($args);
        $process->setInput($sql);
        $process->setTimeout(120);
        $process->run(null, $env);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'mysql command failed'));
        }
    }

    protected function importSqlFile(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
        string $sqlPath,
    ): void {
        $command = sprintf(
            'mysql -h %s -P %s -u %s %s < %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($sqlPath),
        );

        $env = $password !== '' ? ['MYSQL_PWD' => $password] : [];
        $process = SymfonyProcess::fromShellCommandline($command);
        $process->setTimeout(900);
        $process->run(null, $env);

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput() ?: 'mysql import failed'));
        }
    }

    protected function quoteIdentifier(string $value): string
    {
        return str_replace('`', '``', $value);
    }
}
