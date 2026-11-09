<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-request cache for Schema::hasTable / hasColumn (information_schema is expensive).
 */
class SchemaProbe
{
    public static function hasTable(string $table): bool
    {
        static $request = [];

        if (array_key_exists($table, $request)) {
            return $request[$table];
        }

        $request[$table] = (bool) Cache::remember(
            'schema.has_table:'.$table,
            86400,
            fn () => Schema::hasTable($table),
        );

        return $request[$table];
    }

    public static function hasColumn(string $table, string $column): bool
    {
        static $request = [];
        $key = $table.'.'.$column;

        if (array_key_exists($key, $request)) {
            return $request[$key];
        }

        $request[$key] = (bool) Cache::remember(
            'schema.has_column:'.$key,
            86400,
            fn () => Schema::hasTable($table) && Schema::hasColumn($table, $column),
        );

        return $request[$key];
    }

    public static function forget(string $table, ?string $column = null): void
    {
        if ($column === null) {
            Cache::forget('schema.has_table:'.$table);

            return;
        }

        Cache::forget('schema.has_column:'.$table.'.'.$column);
    }
}
