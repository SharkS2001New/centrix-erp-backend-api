<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Schema;

class EffectiveSaleDate
{
    private static ?bool $columnExists = null;

    public static function resolve(?Carbon $completedAt, ?Carbon $createdAt = null): string
    {
        $timestamp = $completedAt ?? $createdAt ?? now();

        return $timestamp->toDateString();
    }

    public static function columnExists(): bool
    {
        if (self::$columnExists === null) {
            self::$columnExists = Schema::hasTable('sales')
                && Schema::hasColumn('sales', 'effective_sale_date');
        }

        return self::$columnExists;
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyFromToDateFilter(
        EloquentBuilder|QueryBuilder $query,
        ?string $from,
        ?string $to,
        string $alias = 'sales',
    ): void {
        if ($from === null && $to === null) {
            return;
        }

        if (self::columnExists()) {
            if ($from !== null) {
                $query->whereDate("{$alias}.effective_sale_date", '>=', $from);
            }
            if ($to !== null) {
                $query->whereDate("{$alias}.effective_sale_date", '<=', $to);
            }

            return;
        }

        $expression = "DATE(COALESCE({$alias}.completed_at, {$alias}.created_at))";
        if ($from !== null) {
            $query->whereRaw("{$expression} >= ?", [$from]);
        }
        if ($to !== null) {
            $query->whereRaw("{$expression} <= ?", [$to]);
        }
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyRange(
        EloquentBuilder|QueryBuilder $query,
        string $from,
        string $to,
        string $column = 'sales.effective_sale_date',
    ): void {
        $query->where($column, '>=', $from)
            ->where($column, '<=', $to);
    }

    /**
     * @param  EloquentBuilder<mixed>|QueryBuilder  $query
     */
    public static function applyRequiredDateRange(
        EloquentBuilder|QueryBuilder $query,
        string $from,
        string $to,
        string $alias = 'sales',
    ): void {
        $query->whereBetween("{$alias}.required_date", [$from, $to]);
    }
}
