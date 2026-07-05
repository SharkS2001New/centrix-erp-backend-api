<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class EffectiveSaleDate
{
    public static function resolve(?Carbon $completedAt, ?Carbon $createdAt = null): string
    {
        $timestamp = $completedAt ?? $createdAt ?? now();

        return $timestamp->toDateString();
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
