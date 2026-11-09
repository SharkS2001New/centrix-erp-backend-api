<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Report views fail with SQLSTATE 1449 when DEFINER is a missing account
 * (e.g. steve@% after a dump from another host). Re-own every view as the
 * current DB username@localhost with SQL SECURITY INVOKER.
 */
class FixMysqlViewDefinersCommand extends Command
{
    protected $signature = 'erp:fix-view-definers
                            {--user= : Definer user (defaults to DB_USERNAME / connection username)}
                            {--host=localhost : Definer host}';

    protected $description = 'Reassign MySQL view DEFINER to the local DB user so reports stop failing with error 1449';

    public function handle(): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'mysql') {
            $this->error('Only MySQL is supported.');

            return self::FAILURE;
        }

        $username = (string) ($this->option('user') ?: $connection->getConfig('username') ?: 'steve');
        $host = (string) ($this->option('host') ?: 'localhost');
        $schema = (string) $connection->getDatabaseName();

        $views = DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME',
            [$schema],
        );

        if ($views === []) {
            $this->warn("No views found in {$schema}.");

            return self::SUCCESS;
        }

        $definer = '`'.str_replace('`', '``', $username).'`@`'.str_replace('`', '``', $host).'`';
        $fixed = 0;
        $failed = 0;

        foreach ($views as $view) {
            $name = (string) $view->name;
            try {
                $row = DB::selectOne('SHOW CREATE VIEW `'.str_replace('`', '``', $name).'`');
                $create = (string) ($row->{'Create View'} ?? '');
                if ($create === '') {
                    $this->warn("Skipped {$name}: empty CREATE VIEW");
                    $failed++;

                    continue;
                }

                $sql = preg_replace('/DEFINER=`[^`]+`@`[^`]+`/', 'DEFINER='.$definer, $create) ?? $create;
                $sql = preg_replace('/\bSQL SECURITY DEFINER\b/i', 'SQL SECURITY INVOKER', $sql) ?? $sql;
                if (! preg_match('/\bSQL SECURITY\b/i', $sql)) {
                    $sql = preg_replace(
                        '/\bVIEW\b/i',
                        'SQL SECURITY INVOKER VIEW',
                        $sql,
                        1,
                    ) ?? $sql;
                }
                $sql = preg_replace('/^CREATE\b/i', 'CREATE OR REPLACE', $sql) ?? $sql;

                DB::statement($sql);
                $fixed++;
                $this->line("Fixed {$name}");
            } catch (\Throwable $e) {
                $failed++;
                $this->error("Failed {$name}: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Reassigned {$fixed} view(s) to {$username}@{$host} (INVOKER). Failures: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
