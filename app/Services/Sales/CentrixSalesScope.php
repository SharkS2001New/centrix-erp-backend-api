<?php

namespace App\Services\Sales;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Centrix operational sales metrics exclude materialized legacy imports.
 * Those rows exist only to support KRA legacy returns / credit notes.
 */
class CentrixSalesScope
{
    /**
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder
     */
    public static function excludeLegacyMaterialized(EloquentBuilder|QueryBuilder $query, ?string $tableAlias = null): EloquentBuilder|QueryBuilder
    {
        $prefix = $tableAlias ? "{$tableAlias}." : '';

        return $query->where(function ($sub) use ($prefix) {
            $sub->whereNull("{$prefix}fulfillment_meta")
                ->orWhereNull("{$prefix}fulfillment_meta->legacy_import")
                ->orWhere("{$prefix}fulfillment_meta->legacy_import", '!=', true);
        });
    }

    /** SQL predicate for MySQL views (e.g. alias `s`). */
    public static function legacyExcludeSql(string $alias = 's'): string
    {
        $column = "{$alias}.fulfillment_meta";

        return "({$column} IS NULL OR JSON_EXTRACT({$column}, '$.legacy_import') IS NULL OR JSON_EXTRACT({$column}, '$.legacy_import') = false OR JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.legacy_import')) = 'false')";
    }
}
