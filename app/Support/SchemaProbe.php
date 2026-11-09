<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Cross-request cache for Schema::hasTable / hasColumn (information_schema is expensive).
 */
class SchemaProbe
{
    protected static int $generation = 0;

    /** @var array<string, bool> */
    protected static array $tables = [];

    /** @var array<string, bool> */
    protected static array $columns = [];

    public static function hasTable(string $table): bool
    {
        $key = self::$generation.'|'.$table;
        if (array_key_exists($key, self::$tables)) {
            return self::$tables[$key];
        }

        // Only cache positive hits across requests. A durable "false" poisons Feature
        // tests / post-migrate boots when an earlier Unit run saw the table missing.
        $cached = Cache::get('schema.has_table:'.$table);
        if ($cached === true) {
            return self::$tables[$key] = true;
        }

        $exists = (bool) Schema::hasTable($table);
        if ($exists) {
            Cache::put('schema.has_table:'.$table, true, 86400);
        } else {
            Cache::forget('schema.has_table:'.$table);
        }

        return self::$tables[$key] = $exists;
    }

    public static function hasColumn(string $table, string $column): bool
    {
        $key = self::$generation.'|'.$table.'.'.$column;
        if (array_key_exists($key, self::$columns)) {
            return self::$columns[$key];
        }

        $cacheKey = 'schema.has_column:'.$table.'.'.$column;
        $cached = Cache::get($cacheKey);
        if ($cached === true) {
            return self::$columns[$key] = true;
        }

        $exists = Schema::hasTable($table) && Schema::hasColumn($table, $column);
        if ($exists) {
            Cache::put($cacheKey, true, 86400);
        } else {
            Cache::forget($cacheKey);
        }

        return self::$columns[$key] = (bool) $exists;
    }

    public static function forget(string $table, ?string $column = null): void
    {
        if ($column === null) {
            Cache::forget('schema.has_table:'.$table);
            self::flushRequestCache();

            return;
        }

        Cache::forget('schema.has_column:'.$table.'.'.$column);
        self::flushRequestCache();
    }

    /** Drop request-local memoization (e.g. between PHPUnit cases). */
    public static function flushRequestCache(): void
    {
        self::$generation++;
        self::$tables = [];
        self::$columns = [];
    }
}
