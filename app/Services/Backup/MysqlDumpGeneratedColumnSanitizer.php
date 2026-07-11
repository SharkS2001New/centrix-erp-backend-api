<?php

namespace App\Services\Backup;

/**
 * Makes mysqldump output restorable when tables use GENERATED ALWAYS columns.
 *
 * mysqldump often emits INSERT values for generated columns (e.g. customer_invoices.balance_due),
 * which MySQL rejects on import with ERROR 3105. We temporarily dump those columns as plain
 * nullable columns, then DROP/ADD them as generated at the end of the file so MySQL recomputes values.
 */
class MysqlDumpGeneratedColumnSanitizer
{
    /**
     * Known Centrix generated columns (fallback when INFORMATION_SCHEMA is unavailable).
     *
     * @var array<string, list<array{name: string, type: string, expression: string, stored: bool}>>
     */
    public const KNOWN_COLUMNS = [
        'customer_invoices' => [
            [
                'name' => 'balance_due',
                'type' => 'DECIMAL(10,2)',
                'expression' => '(invoice_total - amount_paid)',
                'stored' => true,
            ],
        ],
        'stock_take_lines' => [
            [
                'name' => 'variance',
                'type' => 'FLOAT',
                'expression' => '(counted_quantity - system_quantity)',
                'stored' => true,
            ],
        ],
    ];

    /**
     * @param  array<string, list<array{name: string, type: string, expression: string, stored: bool}>>  $columnsByTable
     * @return array{rewritten: bool, tables: list<string>}
     */
    public function sanitizeFile(string $absolutePath, array $columnsByTable = self::KNOWN_COLUMNS): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new DatabaseBackupException(
                'Dump file was not found or is not readable.',
                'dump_missing',
            );
        }

        $sql = file_get_contents($absolutePath);
        if ($sql === false) {
            throw new DatabaseBackupException(
                'Could not read the dump file for generated-column sanitization.',
                'dump_read_failed',
            );
        }

        $result = $this->sanitizeSql($sql, $columnsByTable);
        if (! $result['rewritten']) {
            return $result;
        }

        if (file_put_contents($absolutePath, $result['sql']) === false) {
            throw new DatabaseBackupException(
                'Could not write the sanitized dump file.',
                'dump_write_failed',
            );
        }

        return [
            'rewritten' => true,
            'tables' => $result['tables'],
        ];
    }

    /**
     * @param  array<string, list<array{name: string, type: string, expression: string, stored: bool}>>  $columnsByTable
     * @return array{rewritten: bool, tables: list<string>, sql: string}
     */
    public function sanitizeSql(string $sql, array $columnsByTable = self::KNOWN_COLUMNS): array
    {
        $touched = [];
        $altered = $sql;

        foreach ($columnsByTable as $table => $columns) {
            foreach ($columns as $column) {
                $name = $column['name'];
                $type = $column['type'];
                // Allow commas inside type args (DECIMAL(10,2)) and AS (...) expressions.
                $pattern = '/`'
                    .preg_quote($name, '/')
                    .'`\s+(?:[^,()\n]|\\([^)]*\\))*GENERATED\s+ALWAYS\s+AS\s+\\((?:[^()]|\\([^()]*\\))*\\)\s+(STORED|VIRTUAL)/i';
                $replacement = '`'.$name.'` '.$type.' NULL';
                $count = 0;
                $altered = preg_replace($pattern, $replacement, $altered, -1, $count);
                if (($count ?? 0) > 0) {
                    $touched[$table] = true;
                }
            }
        }

        if ($touched === []) {
            // Already sanitized, or dump omitted generated definitions (unlikely with INSERT values).
            // Still append restore ALTERs only if CREATE still has generated? Nothing to do.
            return [
                'rewritten' => false,
                'tables' => [],
                'sql' => $sql,
            ];
        }

        $footer = "\n\n-- Centrix: restore GENERATED columns after import (avoids ERROR 3105)\n";
        $footer .= "SET @centrix_restore_generated := 1;\n";

        foreach (array_keys($touched) as $table) {
            foreach ($columnsByTable[$table] as $column) {
                $name = $column['name'];
                $type = $column['type'];
                $expression = $column['expression'];
                $stored = ($column['stored'] ?? true) ? 'STORED' : 'VIRTUAL';
                $footer .= sprintf(
                    "ALTER TABLE `%s` DROP COLUMN `%s`, ADD COLUMN `%s` %s GENERATED ALWAYS AS %s %s;\n",
                    $table,
                    $name,
                    $name,
                    $type,
                    $expression,
                    $stored,
                );
            }
        }

        return [
            'rewritten' => true,
            'tables' => array_keys($touched),
            'sql' => rtrim($altered)."\n".$footer,
        ];
    }
}
