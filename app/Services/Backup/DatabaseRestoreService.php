<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DatabaseRestoreService
{
    public function __construct(protected DatabaseBackupService $backups) {}

    /**
     * Restore from a backup already stored on the backup disk.
     *
     * @param  'merge'|'replace'  $mode
     * @param  list<string>|null  $tables  Null/empty = full dump (replace requires confirmFull)
     * @return array{mode: string, tables: list<string>|null, statements_executed: int, statements_skipped: int, truncated: list<string>, source: string}
     */
    public function restoreFromStoredBackup(
        string $filename,
        string $mode = 'merge',
        ?array $tables = null,
        bool $confirmFull = false,
    ): array {
        $backup = $this->backups->findBackup($filename);
        if ($backup === null) {
            throw new DatabaseRestoreException('Backup file not found.', 'backup_not_found');
        }

        return $this->restoreFromAbsolutePath(
            $backup['absolute_path'],
            $mode,
            $tables,
            (bool) $backup['compressed'],
            $confirmFull,
            $backup['filename'],
        );
    }

    /**
     * @param  'merge'|'replace'  $mode
     * @param  list<string>|null  $tables
     * @return array{mode: string, tables: list<string>|null, statements_executed: int, statements_skipped: int, truncated: list<string>, source: string}
     */
    public function restoreFromAbsolutePath(
        string $absolutePath,
        string $mode = 'merge',
        ?array $tables = null,
        bool $compressed = false,
        bool $confirmFull = false,
        ?string $sourceLabel = null,
    ): array {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new DatabaseRestoreException('Restore file is missing or unreadable.', 'file_unreadable');
        }

        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['merge', 'replace'], true)) {
            throw new DatabaseRestoreException('Restore mode must be merge or replace.', 'invalid_mode');
        }

        $normalizedTables = $this->normalizeTables($tables);
        $isFull = $normalizedTables === null;
        if ($isFull && $mode === 'replace' && ! $confirmFull) {
            throw new DatabaseRestoreException(
                'Full-database replace requires confirm_full=true. Prefer table-scoped restore when possible.',
                'confirm_full_required',
            );
        }

        $sqlPath = $compressed || str_ends_with(strtolower($absolutePath), '.gz')
            ? $this->gunzipToTemp($absolutePath)
            : $absolutePath;
        $cleanupSql = $sqlPath !== $absolutePath;

        try {
            return $this->applySqlFile(
                $sqlPath,
                $mode,
                $normalizedTables,
                $sourceLabel ?? basename($absolutePath),
            );
        } finally {
            if ($cleanupSql && is_file($sqlPath)) {
                @unlink($sqlPath);
            }
        }
    }

    /**
     * @param  list<string>|null  $tables
     * @return list<string>|null
     */
    public function normalizeTables(?array $tables): ?array
    {
        if ($tables === null || $tables === []) {
            return null;
        }

        $out = [];
        foreach ($tables as $raw) {
            $name = strtolower(trim((string) $raw));
            if ($name === '' || $name === '*') {
                return null;
            }
            if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name)) {
                throw new DatabaseRestoreException(
                    'Invalid table name "'.$raw.'".',
                    'invalid_table',
                );
            }
            $out[] = $name;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>|null  $tables
     * @return array{mode: string, tables: list<string>|null, statements_executed: int, statements_skipped: int, truncated: list<string>, source: string}
     */
    protected function applySqlFile(
        string $sqlPath,
        string $mode,
        ?array $tables,
        string $sourceLabel,
    ): array {
        $driver = (string) DB::connection()->getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new DatabaseRestoreException(
                "Database driver [{$driver}] is not supported for restore.",
                'unsupported_driver',
            );
        }

        $truncated = [];
        if ($mode === 'replace' && $tables !== null) {
            $truncated = $this->truncateTables($tables, $driver);
        }

        $executed = 0;
        $skipped = 0;
        $handle = fopen($sqlPath, 'rb');
        if ($handle === false) {
            throw new DatabaseRestoreException('Could not open SQL dump for reading.', 'file_unreadable');
        }

        try {
            DB::connection()->disableQueryLog();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                DB::statement("SET SESSION sql_mode=(SELECT REPLACE(@@sql_mode,'NO_ZERO_DATE',''))");
            }

            foreach ($this->iterateStatements($handle) as $statement) {
                $prepared = $this->prepareStatement($statement, $mode, $tables, $driver);
                if ($prepared === null) {
                    $skipped++;
                    continue;
                }

                try {
                    DB::unprepared($prepared);
                    $executed++;
                } catch (\Throwable $e) {
                    // Merge: ignore duplicate-key / already-exists noise when possible.
                    if ($mode === 'merge' && $this->isIgnorableMergeError($e)) {
                        $skipped++;
                        continue;
                    }
                    throw new DatabaseRestoreException(
                        'SQL restore failed: '.$e->getMessage(),
                        'sql_failed',
                        $e,
                    );
                }
            }
        } finally {
            fclose($handle);
            if ($driver === 'mysql' || $driver === 'mariadb') {
                try {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        Log::warning('Platform database restore completed', [
            'source' => $sourceLabel,
            'mode' => $mode,
            'tables' => $tables,
            'statements_executed' => $executed,
            'statements_skipped' => $skipped,
            'truncated' => $truncated,
        ]);

        return [
            'mode' => $mode,
            'tables' => $tables,
            'statements_executed' => $executed,
            'statements_skipped' => $skipped,
            'truncated' => $truncated,
            'source' => $sourceLabel,
        ];
    }

    /**
     * @param  list<string>  $tables
     * @return list<string>
     */
    protected function truncateTables(array $tables, string $driver): array
    {
        $done = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if ($driver === 'sqlite') {
                DB::table($table)->delete();
            } else {
                DB::statement('TRUNCATE TABLE `'.$table.'`');
            }
            $done[] = $table;
        }

        return $done;
    }

    /**
     * @param  resource  $handle
     * @return \Generator<int, string>
     */
    protected function iterateStatements($handle): \Generator
    {
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;

        while (! feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $ch = $chunk[$i];
                $next = $i + 1 < $len ? $chunk[$i + 1] : '';

                if ($inLineComment) {
                    $buffer .= $ch;
                    if ($ch === "\n") {
                        $inLineComment = false;
                    }
                    continue;
                }

                if ($inBlockComment) {
                    $buffer .= $ch;
                    if ($ch === '*' && $next === '/') {
                        $buffer .= $next;
                        $i++;
                        $inBlockComment = false;
                    }
                    continue;
                }

                if (! $inSingle && ! $inDouble && ! $inBacktick) {
                    if ($ch === '-' && $next === '-') {
                        $buffer .= $ch.$next;
                        $i++;
                        $inLineComment = true;
                        continue;
                    }
                    if ($ch === '#') {
                        $buffer .= $ch;
                        $inLineComment = true;
                        continue;
                    }
                    if ($ch === '/' && $next === '*') {
                        $buffer .= $ch.$next;
                        $i++;
                        $inBlockComment = true;
                        continue;
                    }
                }

                if ($ch === "'" && ! $inDouble && ! $inBacktick) {
                    if ($inSingle && $next === "'") {
                        $buffer .= "''";
                        $i++;
                        continue;
                    }
                    $inSingle = ! $inSingle;
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '"' && ! $inSingle && ! $inBacktick) {
                    if ($inDouble && $next === '"') {
                        $buffer .= '""';
                        $i++;
                        continue;
                    }
                    $inDouble = ! $inDouble;
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '`' && ! $inSingle && ! $inDouble) {
                    $inBacktick = ! $inBacktick;
                    $buffer .= $ch;
                    continue;
                }

                if ($ch === '\\' && ($inSingle || $inDouble)) {
                    $buffer .= $ch;
                    if ($next !== '') {
                        $buffer .= $next;
                        $i++;
                    }
                    continue;
                }

                if ($ch === ';' && ! $inSingle && ! $inDouble && ! $inBacktick) {
                    $statement = trim($buffer);
                    $buffer = '';
                    if ($statement !== '') {
                        yield $statement;
                    }
                    continue;
                }

                $buffer .= $ch;
            }
        }

        $tail = trim($buffer);
        if ($tail !== '') {
            yield $tail;
        }
    }

    /**
     * @param  list<string>|null  $tables
     */
    protected function prepareStatement(string $statement, string $mode, ?array $tables, string $driver = 'mysql'): ?string
    {
        $trimmed = ltrim($statement);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            return null;
        }

        // Strip leading /* ... */ comment blocks for classification.
        while (preg_match('/^\/\*.*?\*\/\s*/s', $trimmed) === 1) {
            $trimmed = preg_replace('/^\/\*.*?\*\/\s*/s', '', $trimmed, 1) ?? $trimmed;
        }
        if ($trimmed === '') {
            return null;
        }

        $upper = strtoupper($trimmed);

        if (str_starts_with($upper, 'CREATE DATABASE')
            || str_starts_with($upper, 'DROP DATABASE')
            || str_starts_with($upper, 'USE ')
            || str_starts_with($upper, 'LOCK TABLES')
            || str_starts_with($upper, 'UNLOCK TABLES')
            || str_starts_with($upper, 'SET NAMES')
            || str_starts_with($upper, 'SET CHARACTER')
            || preg_match('/^SET\s+@/i', $trimmed) === 1
        ) {
            return null;
        }

        // Always allow session toggles we may emit ourselves (MySQL).
        if (preg_match('/^SET\s+(FOREIGN_KEY_CHECKS|SQL_MODE|UNIQUE_CHECKS|AUTOCOMMIT)\b/i', $trimmed) === 1) {
            return $driver === 'sqlite' ? null : $trimmed;
        }

        $table = $this->extractTableName($trimmed);
        if ($tables !== null) {
            if ($table === null || ! in_array(strtolower($table), $tables, true)) {
                return null;
            }
        }

        $ignoreInto = $driver === 'sqlite' ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';

        if ($mode === 'merge') {
            if (preg_match('/^(DROP|TRUNCATE|DELETE|ALTER|CREATE|RENAME|REPLACE)\b/i', $trimmed) === 1) {
                // REPLACE INTO is upsert-like; convert to INSERT IGNORE below if INSERT-style.
                if (! preg_match('/^REPLACE\s+INTO\b/i', $trimmed)) {
                    return null;
                }
            }

            if (preg_match('/^INSERT\s+(?:OR\s+IGNORE\s+|IGNORE\s+)?INTO\b/i', $trimmed) === 1) {
                $out = preg_replace('/^INSERT\s+(?:OR\s+IGNORE\s+|IGNORE\s+)?INTO\b/i', $ignoreInto, $trimmed, 1) ?? $trimmed;

                return $driver === 'sqlite' ? str_replace('`', '"', $out) : $out;
            }
            if (preg_match('/^REPLACE\s+INTO\b/i', $trimmed) === 1) {
                $out = preg_replace('/^REPLACE\s+INTO\b/i', $ignoreInto, $trimmed, 1) ?? $trimmed;

                return $driver === 'sqlite' ? str_replace('`', '"', $out) : $out;
            }

            // Skip other DML/DDL in merge mode.
            return null;
        }

        // replace mode: skip DROP DATABASE-style already handled; allow table DDL + DML
        if (preg_match('/^DROP\s+TABLE\b/i', $trimmed) === 1 && $tables !== null) {
            // Table-scoped replace already truncated; skip dump DROP to avoid cascade surprises.
            return null;
        }

        // SQLite dumps / MySQL dumps may use backticks — strip for sqlite.
        if ($driver === 'sqlite') {
            $trimmed = str_replace('`', '"', $trimmed);
        }

        return $trimmed;
    }

    protected function extractTableName(string $statement): ?string
    {
        if (preg_match(
            '/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+[`]?([a-zA-Z0-9_]+)[`]?/i',
            $statement,
            $m,
        ) === 1) {
            return $m[1];
        }
        if (preg_match(
            '/^(?:UPDATE|DELETE\s+FROM|TRUNCATE(?:\s+TABLE)?|ALTER\s+TABLE|DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+[`]?([a-zA-Z0-9_]+)[`]?/i',
            $statement,
            $m,
        ) === 1) {
            return $m[1];
        }

        return null;
    }

    protected function isIgnorableMergeError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate')
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'already exists');
    }

    protected function gunzipToTemp(string $gzPath): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'erp_restore_');
        if ($temp === false) {
            throw new DatabaseRestoreException('Could not create temp file for decompress.', 'temp_failed');
        }
        $sqlPath = $temp.'.sql';
        @unlink($temp);

        $in = @gzopen($gzPath, 'rb');
        if ($in === false) {
            throw new DatabaseRestoreException('Could not decompress .gz backup.', 'decompress_failed');
        }
        $out = fopen($sqlPath, 'wb');
        if ($out === false) {
            gzclose($in);
            throw new DatabaseRestoreException('Could not write decompressed SQL.', 'temp_failed');
        }

        try {
            while (! gzeof($in)) {
                $chunk = gzread($in, 65536);
                if ($chunk === false) {
                    throw new DatabaseRestoreException('Failed while decompressing backup.', 'decompress_failed');
                }
                if ($chunk !== '' && fwrite($out, $chunk) === false) {
                    throw new DatabaseRestoreException('Failed while writing decompressed SQL.', 'temp_failed');
                }
            }
        } finally {
            gzclose($in);
            fclose($out);
        }

        return $sqlPath;
    }
}
