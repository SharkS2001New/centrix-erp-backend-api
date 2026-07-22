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
     * Order statuses included in backoffice sales performance reports.
     * Excludes draft/held/cancelled/expired so reports can trace work in progress
     * (booked → completed, including unpaid / partial payments).
     *
     * @return list<string>
     */
    public static function reportPipelineStatuses(): array
    {
        return [
            'booked',
            'pending',
            'unpaid',
            'pending_payment',
            'paid',
            'processed',
            'delivered',
            'completed',
        ];
    }

    /** SQL: `s.status IN ('booked', …)` */
    public static function reportPipelineStatusSql(string $column = 's.status'): string
    {
        $list = implode(', ', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            self::reportPipelineStatuses(),
        ));

        return "{$column} IN ({$list})";
    }

    /**
     * Report date bucket: order placed / booked date.
     * Use created_at (not completed_at) so pipeline orders stay on the day the
     * salesperson placed them — matching the sales list "Placed date" filter.
     */
    public static function reportSaleDateSql(string $alias = 's'): string
    {
        return "DATE({$alias}.created_at)";
    }

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

    /**
     * Restrict a query builder to sales pipeline statuses used by reports.
     *
     * @param  EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     * @return EloquentBuilder<\Illuminate\Database\Eloquent\Model>|QueryBuilder
     */
    public static function whereReportPipelineStatus(EloquentBuilder|QueryBuilder $query, string $column = 'status'): EloquentBuilder|QueryBuilder
    {
        return $query->whereIn($column, self::reportPipelineStatuses());
    }
}
