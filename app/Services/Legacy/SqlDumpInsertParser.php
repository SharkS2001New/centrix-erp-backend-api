<?php

namespace App\Services\Legacy;

/**
 * Parse MySQL dump INSERT INTO ... VALUES (...) tuples from SQL text.
 *
 * Avoids matching VALUES with a regex — large product dumps and semicolons
 * inside quoted strings make preg_match_all miss or truncate rows.
 */
class SqlDumpInsertParser
{
    /**
     * Extract all VALUES blobs for a table (supports multiple INSERT blocks).
     *
     * Handles:
     * - INSERT INTO `table` VALUES (...);
     * - INSERT INTO table VALUES (...);
     * - INSERT INTO `table` (col1, col2) VALUES (...);
     * - INSERT IGNORE / REPLACE INTO
     * - Trailing mysqldump markers or a bare semicolon
     */
    public function extractInsertValues(string $sql, string $table): ?string
    {
        $blobs = [];
        foreach ($this->iterateInserts($sql, [$table]) as $insert) {
            $trimmed = trim($insert['values']);
            if ($trimmed !== '') {
                $blobs[] = $trimmed;
            }
        }

        return $blobs === [] ? null : implode(",\n", $blobs);
    }

    public function detectTableName(string $sql): ?string
    {
        $tables = $this->detectAllTableNames($sql);

        return $tables[0] ?? null;
    }

    /**
     * @return list<string> Distinct table names that have INSERT statements, in first-seen order.
     */
    public function detectAllTableNames(string $sql): array
    {
        $seen = [];
        $tables = [];
        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            $found = $this->findNextInsert($sql, $offset);
            if ($found === null) {
                break;
            }
            $name = $found['table'];
            $offset = $found['tableEnd'];
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $tables[] = $name;
        }

        return $tables;
    }

    /**
     * @param  list<string>|string  $tables
     * @return list<array{columns: list<string>|null, row: list<mixed>}>
     */
    public function loadRowsWithColumns(string $sql, string|array $tables): array
    {
        $rows = [];
        foreach ($this->iterateInserts($sql, $tables) as $insert) {
            foreach ($this->splitSqlTuples($insert['values']) as $tuple) {
                $rows[] = [
                    'columns' => $insert['columns'],
                    'row' => $this->parseSqlTuple($tuple),
                ];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    public function splitSqlTuples(string $valuesBlob): array
    {
        $tuples = [];
        $depth = 0;
        $inString = false;
        $escape = false;
        $start = null;
        $length = strlen($valuesBlob);

        for ($i = 0; $i < $length; $i++) {
            $ch = $valuesBlob[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === "'") {
                    if ($i + 1 < $length && $valuesBlob[$i + 1] === "'") {
                        $i++;
                    } else {
                        $inString = false;
                    }
                }

                continue;
            }

            if ($ch === "'") {
                $inString = true;

                continue;
            }

            if ($ch === '(') {
                if ($depth === 0) {
                    $start = $i;
                }
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth === 0 && $start !== null) {
                    $tuples[] = substr($valuesBlob, $start, $i - $start + 1);
                    $start = null;
                }
            }
        }

        return $tuples;
    }

    /** @return list<mixed> */
    public function parseSqlTuple(string $tupleText): array
    {
        $inner = trim($tupleText);
        if (str_starts_with($inner, '(') && str_ends_with($inner, ')')) {
            $inner = substr($inner, 1, -1);
        }

        $values = [];
        $token = '';
        $inString = false;
        $escape = false;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $ch = $inner[$i];

            if ($escape) {
                $token .= $ch;
                $escape = false;

                continue;
            }

            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === "'") {
                    if ($i + 1 < $length && $inner[$i + 1] === "'") {
                        $token .= "'";
                        $i++;

                        continue;
                    }
                    $inString = false;
                } else {
                    $token .= $ch;
                }

                continue;
            }

            if ($ch === "'") {
                if (preg_match('/^(?:N|_utf[a-z0-9]+|_latin1|_bin)$/i', trim($token))) {
                    $token = '';
                }
                $inString = true;

                continue;
            }

            if ($ch === ',') {
                $values[] = $this->parseToken(trim($token));
                $token = '';

                continue;
            }

            $token .= $ch;
        }

        $values[] = $this->parseToken(trim($token));

        return $values;
    }

    /** @return list<list<mixed>> */
    public function loadRows(string $sql, string $table): array
    {
        $rows = [];
        foreach ($this->loadRowsWithColumns($sql, $table) as $insertRow) {
            $rows[] = $insertRow['row'];
        }

        return $rows;
    }

    /**
     * @param  list<string>|string  $tables
     * @return \Generator<int, array{table: string, columns: list<string>|null, values: string}>
     */
    public function iterateInserts(string $sql, string|array $tables): \Generator
    {
        $wanted = [];
        foreach ((array) $tables as $table) {
            $wanted[strtolower((string) $table)] = true;
        }

        $offset = 0;
        $length = strlen($sql);

        while ($offset < $length) {
            $found = $this->findNextInsert($sql, $offset);
            if ($found === null) {
                return;
            }

            $offset = $found['tableEnd'];
            if (! isset($wanted[strtolower($found['table'])])) {
                continue;
            }

            $i = $this->skipWs($sql, $offset);
            $columns = null;
            if ($i < $length && $sql[$i] === '(') {
                [$columns, $i] = $this->parseIdentifierList($sql, $i);
                $i = $this->skipWs($sql, $i);
            }

            if (! $this->matchKeyword($sql, $i, 'VALUES') && ! $this->matchKeyword($sql, $i, 'VALUE')) {
                $offset = $this->skipToSemicolon($sql, $i);

                continue;
            }

            $keywordLen = $this->matchKeyword($sql, $i, 'VALUES') ? 6 : 5;
            $i = $this->skipWs($sql, $i + $keywordLen);
            $valuesEnd = $this->endOfValues($sql, $i);
            $values = substr($sql, $i, $valuesEnd - $i);

            yield [
                'table' => $found['table'],
                'columns' => $columns,
                'values' => $values,
            ];

            $offset = min($length, $valuesEnd + 1);
        }
    }

    /**
     * @return array{table: string, tableEnd: int}|null
     */
    protected function findNextInsert(string $sql, int $offset): ?array
    {
        $length = strlen($sql);

        while ($offset < $length) {
            $pos = stripos($sql, 'insert', $offset);
            $replacePos = stripos($sql, 'replace', $offset);
            if ($pos === false && $replacePos === false) {
                return null;
            }
            if ($pos === false || ($replacePos !== false && $replacePos < $pos)) {
                $pos = $replacePos;
                $keywordLen = 7;
            } else {
                $keywordLen = 6;
            }

            if ($pos > 0 && $this->isIdentifierChar($sql[$pos - 1])) {
                $offset = $pos + $keywordLen;

                continue;
            }

            $i = $this->skipWs($sql, $pos + $keywordLen);
            foreach (['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'] as $hint) {
                if ($this->matchKeyword($sql, $i, $hint)) {
                    $i = $this->skipWs($sql, $i + strlen($hint));
                }
            }

            if (! $this->matchKeyword($sql, $i, 'INTO')) {
                $offset = $pos + $keywordLen;

                continue;
            }

            $i = $this->skipWs($sql, $i + 4);
            [$table, $tableEnd] = $this->parseTableName($sql, $i);
            if ($table === null || $table === '') {
                $offset = $pos + $keywordLen;

                continue;
            }

            return ['table' => $table, 'tableEnd' => $tableEnd];
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    protected function parseTableName(string $sql, int $i): array
    {
        [$first, $i] = $this->parseIdentifier($sql, $i);
        if ($first === null) {
            return [null, $i];
        }

        $i = $this->skipWs($sql, $i);
        if (($sql[$i] ?? '') === '.') {
            $i = $this->skipWs($sql, $i + 1);
            [$second, $i] = $this->parseIdentifier($sql, $i);
            if ($second !== null) {
                return [$second, $i];
            }
        }

        return [$first, $i];
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    protected function parseIdentifier(string $sql, int $i): array
    {
        $length = strlen($sql);
        if ($i >= $length) {
            return [null, $i];
        }

        $ch = $sql[$i];
        if ($ch === '`' || $ch === '"' || $ch === "'") {
            $end = strpos($sql, $ch, $i + 1);
            if ($end === false) {
                return [null, $i];
            }

            return [substr($sql, $i + 1, $end - $i - 1), $end + 1];
        }

        if (! $this->isIdentifierChar($ch)) {
            return [null, $i];
        }

        $start = $i;
        $i++;
        while ($i < $length && $this->isIdentifierChar($sql[$i])) {
            $i++;
        }

        return [substr($sql, $start, $i - $start), $i];
    }

    /**
     * @return array{0: list<string>, 1: int}
     */
    protected function parseIdentifierList(string $sql, int $i): array
    {
        $length = strlen($sql);
        $names = [];
        if (($sql[$i] ?? '') !== '(') {
            return [$names, $i];
        }
        $i++;

        while ($i < $length) {
            $i = $this->skipWs($sql, $i);
            if (($sql[$i] ?? '') === ')') {
                return [$names, $i + 1];
            }

            [$name, $i] = $this->parseTableName($sql, $i);
            if ($name !== null && $name !== '') {
                $names[] = $name;
            }

            $i = $this->skipWs($sql, $i);
            if (($sql[$i] ?? '') === ',') {
                $i++;
            }
        }

        return [$names, $i];
    }

    protected function endOfValues(string $sql, int $start): int
    {
        $length = strlen($sql);
        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $length; $i++) {
            $ch = $sql[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === "'") {
                    if ($i + 1 < $length && $sql[$i + 1] === "'") {
                        $i++;
                    } else {
                        $inString = false;
                    }
                }

                continue;
            }

            if ($ch === "'") {
                $inString = true;

                continue;
            }

            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                if ($depth > 0) {
                    $depth--;
                }
            } elseif ($ch === ';' && $depth === 0) {
                return $i;
            }
        }

        return $length;
    }

    protected function skipToSemicolon(string $sql, int $i): int
    {
        return min(strlen($sql), $this->endOfValues($sql, $i) + 1);
    }

    protected function skipWs(string $sql, int $i): int
    {
        $length = strlen($sql);

        while ($i < $length) {
            $ch = $sql[$i];
            if ($ch === ' ' || $ch === "\t" || $ch === "\n" || $ch === "\r") {
                $i++;

                continue;
            }

            if ($ch === '#' || ($ch === '-' && ($sql[$i + 1] ?? '') === '-')) {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $length : $nl + 1;

                continue;
            }

            if ($ch === '/' && ($sql[$i + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;

                continue;
            }

            break;
        }

        return $i;
    }

    protected function matchKeyword(string $sql, int $i, string $keyword): bool
    {
        $len = strlen($keyword);
        if (strncasecmp(substr($sql, $i, $len), $keyword, $len) !== 0) {
            return false;
        }

        $next = $sql[$i + $len] ?? '';

        return $next === '' || ! $this->isIdentifierChar($next);
    }

    protected function isIdentifierChar(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9')
            || ($ch >= 'A' && $ch <= 'Z')
            || ($ch >= 'a' && $ch <= 'z')
            || $ch === '_'
            || $ch === '$';
    }

    protected function parseToken(string $raw): mixed
    {
        if ($raw === '' || strtoupper($raw) === 'NULL') {
            return null;
        }

        if (preg_match('/^-?\d+\.\d+$/', $raw)) {
            return (float) $raw;
        }

        if (preg_match('/^-?\d+$/', $raw)) {
            if (str_starts_with($raw, '0') && strlen($raw) > 1) {
                return $raw;
            }

            return (int) $raw;
        }

        return $raw;
    }
}
