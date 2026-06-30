<?php

namespace App\Services\Legacy;

/**
 * Parse MySQL dump INSERT INTO ... VALUES (...) tuples from SQL text.
 */
class SqlDumpInsertParser
{
    public function extractInsertValues(string $sql, string $table): ?string
    {
        $pattern = '/INSERT\s+INTO\s+`'.preg_quote($table, '/').'`\s+VALUES\s*(.+?);\s*(?:\/\*|UNLOCK|\/\*!40000)/is';
        if (! preg_match($pattern, $sql, $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    public function detectTableName(string $sql): ?string
    {
        if (preg_match('/INSERT\s+INTO\s+`([^`]+)`/i', $sql, $matches)) {
            return $matches[1];
        }

        return null;
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
