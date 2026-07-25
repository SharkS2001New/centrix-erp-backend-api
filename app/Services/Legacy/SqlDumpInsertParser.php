<?php

namespace App\Services\Legacy;

/**
 * Parse MySQL dump INSERT INTO ... VALUES (...) tuples from SQL text.
 */
class SqlDumpInsertParser
{
    /**
     * Extract all VALUES blobs for a table (supports multiple INSERT blocks).
     *
     * Handles:
     * - INSERT INTO `table` VALUES (...);
     * - INSERT INTO `table` (col1, col2) VALUES (...);
     * - Trailing mysqldump markers or a bare semicolon
     */
    public function extractInsertValues(string $sql, string $table): ?string
    {
        $quoted = preg_quote($table, '/');
        $pattern = '/INSERT\s+INTO\s+`'.$quoted.'`\s*(?:\([^)]*\)\s*)?VALUES\s*(.+?);/is';
        if (! preg_match_all($pattern, $sql, $matches) || $matches[1] === []) {
            return null;
        }

        $blobs = [];
        foreach ($matches[1] as $blob) {
            $trimmed = trim((string) $blob);
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
        if (! preg_match_all('/INSERT\s+INTO\s+`([^`]+)`/i', $sql, $matches)) {
            return [];
        }

        $seen = [];
        $tables = [];
        foreach ($matches[1] as $table) {
            $name = (string) $table;
            if ($name === '' || isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $tables[] = $name;
        }

        return $tables;
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
                    $inString = false;
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
        $blob = $this->extractInsertValues($sql, $table);
        if ($blob === null || $blob === '') {
            return [];
        }

        $rows = [];
        foreach ($this->splitSqlTuples($blob) as $tuple) {
            $rows[] = $this->parseSqlTuple($tuple);
        }

        return $rows;
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
