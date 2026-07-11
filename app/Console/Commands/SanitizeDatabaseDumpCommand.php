<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackupException;
use App\Services\Backup\MysqlDumpGeneratedColumnSanitizer;
use Illuminate\Console\Command;

class SanitizeDatabaseDumpCommand extends Command
{
    protected $signature = 'erp:sanitize-database-dump
        {path : Absolute path to a .sql dump (gunzip .sql.gz first if needed)}
        {--output= : Write to this path instead of overwriting the input}';

    protected $description = 'Fix mysqldump files so GENERATED columns (e.g. customer_invoices.balance_due) restore without ERROR 3105';

    public function handle(MysqlDumpGeneratedColumnSanitizer $sanitizer): int
    {
        $path = (string) $this->argument('path');
        $output = $this->option('output');

        if (! is_file($path)) {
            $this->error('File not found: '.$path);

            return self::FAILURE;
        }

        if (str_ends_with(strtolower($path), '.gz')) {
            $this->error('Gunzip the file first (e.g. gunzip -k your.dump.sql.gz), then sanitize the .sql.');

            return self::FAILURE;
        }

        $workPath = $path;
        if (is_string($output) && $output !== '') {
            if (! copy($path, $output)) {
                $this->error('Could not copy dump to output path.');

                return self::FAILURE;
            }
            $workPath = $output;
        }

        try {
            $result = $sanitizer->sanitizeFile($workPath);
        } catch (DatabaseBackupException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $result['rewritten']) {
            $this->warn('No GENERATED column definitions were rewritten (already sanitized, or not present).');
            $this->info('If import still fails with ERROR 3105, the dump may use VALUES without a CREATE match — re-run a fresh Centrix backup.');

            return self::SUCCESS;
        }

        $this->info('Sanitized tables: '.implode(', ', $result['tables']));
        $this->info('Ready to import: '.$workPath);

        return self::SUCCESS;
    }
}
