<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class DatabaseRestoreService
{
    /** Max rows per INSERT statement after chunking (mysqldump extended inserts). */
    public const INSERT_CHUNK_ROWS = 250;

    /** Soft byte cap per INSERT chunk (~0.75 MiB) to stay under typical max_allowed_packet. */
    public const INSERT_CHUNK_BYTES = 786432;

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

        if ($tables !== null && $tables === []) {
            throw new DatabaseRestoreException(
                'No matching tables to restore (requested tables are missing on this database).',
                'no_tables',
            );
        }

        if (DB::connection()->transactionLevel() > 0) {
            throw new DatabaseRestoreException(
                'Database restore cannot run inside an active transaction. Retry the restore after the transaction has completed.',
                'restore_in_transaction',
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

        $generatedByTable = $this->generatedColumnsByTable($tables);

        try {
            DB::connection()->disableQueryLog();
            if ($driver === 'mysql' || $driver === 'mariadb') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                DB::statement('SET UNIQUE_CHECKS=0');
                try {
                    // Prefer a larger packet size for extended inserts (best-effort).
                    DB::statement('SET SESSION max_allowed_packet=67108864');
                } catch (\Throwable) {
                    // Host may forbid changing max_allowed_packet.
                }
                DB::statement("SET SESSION sql_mode=(SELECT REPLACE(REPLACE(@@sql_mode,'NO_ZERO_DATE',''),'NO_ZERO_IN_DATE',''))");
            }

            foreach ($this->iterateStatements($handle) as $statement) {
                $prepared = $this->prepareStatement($statement, $mode, $tables, $driver, $generatedByTable);
                if ($prepared === null) {
                    $skipped++;
                    continue;
                }

                foreach ($this->expandExecutableStatements($prepared, $driver) as $chunkSql) {
                    try {
                        DB::unprepared($chunkSql);
                        $executed++;
                    } catch (\Throwable $e) {
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
            }
        } finally {
            fclose($handle);
            if ($driver === 'mysql' || $driver === 'mariadb') {
                try {
                    DB::statement('SET UNIQUE_CHECKS=1');
                    DB::statement('SET FOREIGN_KEY_CHECKS=1');
                } catch (\Throwable) {
                    // best-effort
                }
            }
        }

        if ($tables !== null && $executed === 0) {
            throw new DatabaseRestoreException(
                'Restore finished with 0 statements executed for the selected tables. The dump may not contain those tables, or all rows were skipped.',
                'nothing_restored',
            );
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
     * Split oversized INSERT … VALUES (…),(…) into smaller statements.
     *
     * @return list<string>
     */
    public function expandExecutableStatements(string $sql, string $driver = 'mysql'): array
    {
        if (! preg_match('/^(INSERT(?:\s+OR\s+IGNORE|\s+IGNORE)?|REPLACE)\s+INTO\b/i', $sql)) {
            return [$sql];
        }

        if (! preg_match('/^(.*?\bVALUES)\s*(.+)$/is', $sql, $m)) {
            return [$sql];
        }

        $prefix = rtrim($m[1]);
        $valuesBlob = trim($m[2]);
        $valuesBlob = rtrim($valuesBlob, "; \t\n\r");

        $tuples = $this->splitValueTuples($valuesBlob);
        if (count($tuples) <= 1) {
            return [$sql];
        }

        $chunks = [];
        $batch = [];
        $batchBytes = 0;
        foreach ($tuples as $tuple) {
            $tupleBytes = strlen($tuple);
            $wouldExceedRows = count($batch) >= self::INSERT_CHUNK_ROWS;
            $wouldExceedBytes = $batch !== [] && ($batchBytes + $tupleBytes + 1) > self::INSERT_CHUNK_BYTES;
            if ($wouldExceedRows || $wouldExceedBytes) {
                $chunks[] = $prefix.' '.implode(',', $batch);
                $batch = [];
                $batchBytes = 0;
            }
            $batch[] = $tuple;
            $batchBytes += $tupleBytes + 1;
        }
        if ($batch !== []) {
            $chunks[] = $prefix.' '.implode(',', $batch);
        }

        if ($driver === 'sqlite') {
            return array_map(static fn (string $s) => str_replace('`', '"', $s), $chunks);
        }

        return $chunks;
    }

    /**
     * Split a VALUES blob into "(…)" tuples, respecting quotes and nested parentheses.
     *
     * @return list<string>
     */
    public function splitValueTuples(string $valuesBlob): array
    {
        $tuples = [];
        $len = strlen($valuesBlob);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $start = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $valuesBlob[$i];
            $next = $i + 1 < $len ? $valuesBlob[$i + 1] : '';

            if ($inSingle) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                    continue;
                }
                if ($ch === "'" && $next === "'") {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }

            if ($inDouble) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                    continue;
                }
                if ($ch === '"' && $next === '"') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }

            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
                continue;
            }

            if ($ch === ')') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $tuples[] = substr($valuesBlob, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $tuples;
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
        $pendingEscape = false;

        while (! feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $len = strlen($chunk);
            for ($i = 0; $i < $len; $i++) {
                $ch = $chunk[$i];
                $next = $i + 1 < $len ? $chunk[$i + 1] : '';

                if ($pendingEscape && ($inSingle || $inDouble)) {
                    $buffer .= $ch;
                    $pendingEscape = false;
                    continue;
                }

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
                    } else {
                        $pendingEscape = true;
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
     * @param  array<string, list<string>>  $generatedByTable
     */
    protected function prepareStatement(
        string $statement,
        string $mode,
        ?array $tables,
        string $driver = 'mysql',
        array $generatedByTable = [],
    ): ?string {
        $trimmed = ltrim($statement);
        if ($trimmed === '' || str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            return null;
        }

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
                || preg_match('/^SET\s+(?:(?:SESSION|LOCAL|GLOBAL)\s+)?AUTOCOMMIT\b/i', $trimmed) === 1
                || preg_match('/^(START\s+TRANSACTION|BEGIN(?:\s+WORK)?|COMMIT(?:\s+WORK)?|ROLLBACK(?:\s+WORK)?)(?:\s|$)/i', $trimmed) === 1
        ) {
                return null;
            }

            if (preg_match('/^SET\s+(FOREIGN_KEY_CHECKS|SQL_MODE|UNIQUE_CHECKS|MAX_ALLOWED_PACKET)\b/i', $trimmed) === 1) {
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
                if (! preg_match('/^REPLACE\s+INTO\b/i', $trimmed)) {
                    return null;
                }
            }

            if (preg_match('/^INSERT\s+(?:OR\s+IGNORE\s+|IGNORE\s+)?INTO\b/i', $trimmed) === 1) {
                $out = preg_replace('/^INSERT\s+(?:OR\s+IGNORE\s+|IGNORE\s+)?INTO\b/i', $ignoreInto, $trimmed, 1) ?? $trimmed;
                $out = $this->stripGeneratedColumnsFromInsert($out, $table, $generatedByTable);

                return $driver === 'sqlite' ? str_replace('`', '"', $out) : $out;
            }
            if (preg_match('/^REPLACE\s+INTO\b/i', $trimmed) === 1) {
                $out = preg_replace('/^REPLACE\s+INTO\b/i', $ignoreInto, $trimmed, 1) ?? $trimmed;
                $out = $this->stripGeneratedColumnsFromInsert($out, $table, $generatedByTable);

                return $driver === 'sqlite' ? str_replace('`', '"', $out) : $out;
            }

            return null;
        }

        if (preg_match('/^DROP\s+TABLE\b/i', $trimmed) === 1 && $tables !== null) {
            return null;
        }

        if (preg_match('/^INSERT\s+(?:OR\s+IGNORE\s+|IGNORE\s+)?INTO\b/i', $trimmed) === 1
            || preg_match('/^REPLACE\s+INTO\b/i', $trimmed) === 1) {
            $trimmed = $this->stripGeneratedColumnsFromInsert($trimmed, $table, $generatedByTable);
        }

        if ($driver === 'sqlite') {
            $trimmed = str_replace('`', '"', $trimmed);
        }

        return $trimmed;
    }

    /**
     * Remove GENERATED ALWAYS columns from INSERT column lists + matching VALUES
     * so merge into live tables does not hit ERROR 3105.
     *
     * @param  array<string, list<string>>  $generatedByTable
     */
    public function stripGeneratedColumnsFromInsert(string $sql, ?string $table, array $generatedByTable): string
    {
        if ($table === null) {
            return $sql;
        }
        $gen = $generatedByTable[strtolower($table)] ?? [];
        if ($gen === []) {
            return $sql;
        }

        if (! preg_match(
            '/^(INSERT(?:\s+OR\s+IGNORE|\s+IGNORE)?|REPLACE)\s+INTO\s+[`]?'.preg_quote($table, '/').'[`]?\s*\(([^)]+)\)\s*VALUES\s*(.+)$/is',
            $sql,
            $m,
        )) {
            // INSERT without column list — cannot safely strip; leave as-is.
            return $sql;
        }

        $verb = $m[1];
        $colsRaw = $m[2];
        $valuesBlob = rtrim(trim($m[3]), ';');
        $cols = array_map(
            static fn (string $c) => strtolower(trim(str_replace(['`', '"'], '', $c))),
            explode(',', $colsRaw),
        );
        $dropIndexes = [];
        foreach ($cols as $i => $col) {
            if (in_array($col, $gen, true)) {
                $dropIndexes[$i] = true;
            }
        }
        if ($dropIndexes === []) {
            return $sql;
        }

        $keepCols = [];
        foreach ($cols as $i => $col) {
            if (! isset($dropIndexes[$i])) {
                $keepCols[] = '`'.$col.'`';
            }
        }

        $tuples = $this->splitValueTuples($valuesBlob);
        $newTuples = [];
        foreach ($tuples as $tuple) {
            $inner = substr($tuple, 1, -1);
            $parts = $this->splitCsvRespectingQuotes($inner);
            if (count($parts) !== count($cols)) {
                // Mismatch — skip stripping for this statement.
                return $sql;
            }
            $kept = [];
            foreach ($parts as $i => $part) {
                if (! isset($dropIndexes[$i])) {
                    $kept[] = $part;
                }
            }
            $newTuples[] = '('.implode(',', $kept).')';
        }

        return $verb.' INTO `'.$table.'` ('.implode(',', $keepCols).') VALUES '.implode(',', $newTuples);
    }

    /**
     * @return list<string>
     */
    protected function splitCsvRespectingQuotes(string $inner): array
    {
        $parts = [];
        $len = strlen($inner);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $start = 0;

        for ($i = 0; $i < $len; $i++) {
            $ch = $inner[$i];
            $next = $i + 1 < $len ? $inner[$i + 1] : '';

            if ($inSingle) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                    continue;
                }
                if ($ch === "'" && $next === "'") {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    $inSingle = false;
                }
                continue;
            }
            if ($inDouble) {
                if ($ch === '\\' && $next !== '') {
                    $i++;
                    continue;
                }
                if ($ch === '"' && $next === '"') {
                    $i++;
                    continue;
                }
                if ($ch === '"') {
                    $inDouble = false;
                }
                continue;
            }
            if ($ch === "'") {
                $inSingle = true;
                continue;
            }
            if ($ch === '"') {
                $inDouble = true;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = trim(substr($inner, $start, $i - $start));
                $start = $i + 1;
            }
        }
        $parts[] = trim(substr($inner, $start));

        return $parts;
    }

    /**
     * @param  list<string>|null  $tables
     * @return array<string, list<string>>
     */
    protected function generatedColumnsByTable(?array $tables): array
    {
        $map = [];
        foreach (MysqlDumpGeneratedColumnSanitizer::KNOWN_COLUMNS as $table => $cols) {
            if ($tables !== null && ! in_array($table, $tables, true)) {
                continue;
            }
            $map[$table] = array_map(static fn (array $c) => strtolower((string) $c['name']), $cols);
        }

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $map;
        }

        try {
            $rows = DB::select(
                "SELECT TABLE_NAME, COLUMN_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND EXTRA LIKE '%GENERATED%'"
            );
            foreach ($rows as $row) {
                $table = strtolower((string) ($row->TABLE_NAME ?? ''));
                $col = strtolower((string) ($row->COLUMN_NAME ?? ''));
                if ($table === '' || $col === '') {
                    continue;
                }
                if ($tables !== null && ! in_array($table, $tables, true)) {
                    continue;
                }
                $map[$table] ??= [];
                if (! in_array($col, $map[$table], true)) {
                    $map[$table][] = $col;
                }
            }
        } catch (\Throwable) {
            // Keep known defaults.
        }

        return $map;
    }

    protected function extractTableName(string $statement): ?string
    {
        if (preg_match(
            '/^(?:INSERT(?:\s+(?:OR\s+IGNORE|IGNORE))?|REPLACE)\s+INTO\s+[`]?([a-zA-Z0-9_]+)[`]?/i',
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
