<?php

namespace App\Services\Reports;

use App\Models\CustomReportTemplate;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReportBuilderService
{
    public function schema(): array
    {
        $sources = config('report_builder.sources', []);
        $out = [];
        $modules = [];
        foreach ($sources as $key => $source) {
            $fields = [];
            foreach ($source['fields'] as $fieldKey => $field) {
                $fields[] = [
                    'key' => $fieldKey,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'groupable' => (bool) ($field['groupable'] ?? false),
                    'aggregates' => $field['aggregates'] ?? [],
                ];
            }
            $module = $source['module'] ?? 'General';
            $modules[$module] = ($modules[$module] ?? 0) + 1;
            $out[] = [
                'key' => $key,
                'label' => $source['label'],
                'module' => $module,
                'description' => $source['description'] ?? '',
                'default_date_column' => $source['default_date_column'] ?? null,
                'fields' => $fields,
            ];
        }

        ksort($modules);

        return [
            'sources' => $out,
            'modules' => array_map(
                fn (string $name, int $count) => ['name' => $name, 'source_count' => $count],
                array_keys($modules),
                array_values($modules)
            ),
            'aggregates' => config('report_builder.aggregates', []),
            'chart_types' => config('report_builder.chart_types', []),
            'max_columns' => config('report_builder.max_columns'),
            'max_group_by' => config('report_builder.max_group_by'),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    public function run(User $user, array $spec, array $filters = []): LengthAwarePaginator
    {
        $spec = $this->validateSpec($spec);
        $query = $this->buildQuery($user, $spec, $filters);
        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function validateSpec(array $spec): array
    {
        $sourceKey = $spec['source'] ?? null;
        $source = config("report_builder.sources.{$sourceKey}");
        if (! $source) {
            throw ValidationException::withMessages(['source' => 'Invalid data source.']);
        }

        $columns = $spec['columns'] ?? [];
        if (! is_array($columns) || count($columns) < 1) {
            throw ValidationException::withMessages(['columns' => 'Select at least one column.']);
        }
        $maxColumns = config('report_builder.max_columns');
        if ($maxColumns !== null && $maxColumns > 0 && count($columns) > (int) $maxColumns) {
            throw ValidationException::withMessages(['columns' => 'Too many columns.']);
        }

        $groupBy = $spec['group_by'] ?? [];
        if (! is_array($groupBy)) {
            $groupBy = [];
        }
        $maxGroupBy = config('report_builder.max_group_by');
        if ($maxGroupBy !== null && $maxGroupBy > 0 && count($groupBy) > (int) $maxGroupBy) {
            throw ValidationException::withMessages(['group_by' => 'Too many group-by fields.']);
        }

        $validatedColumns = [];
        $outputKeys = [];
        foreach ($columns as $i => $col) {
            $fieldKey = $col['field'] ?? null;
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(["columns.{$i}.field" => "Unknown field: {$fieldKey}"]);
            }

            $aggregate = $col['aggregate'] ?? null;
            if ($groupBy) {
                if (! in_array($fieldKey, $groupBy, true)) {
                    $aggregate = $aggregate ?: 'sum';
                    if (! in_array($aggregate, $field['aggregates'] ?? [], true)) {
                        throw ValidationException::withMessages(["columns.{$i}.aggregate" => "Field {$fieldKey} cannot use aggregate {$aggregate}."]);
                    }
                } else {
                    $aggregate = null;
                }
            } else {
                $aggregate = null;
            }

            $alias = $col['alias'] ?? $fieldKey.($aggregate ? "_{$aggregate}" : '');
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $alias)) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Invalid column alias.']);
            }
            if (isset($outputKeys[$alias])) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Duplicate column alias.']);
            }
            $outputKeys[$alias] = true;

            $validatedColumns[] = [
                'field' => $fieldKey,
                'label' => $col['label'] ?? $field['label'],
                'alias' => $alias,
                'aggregate' => $aggregate,
            ];
        }

        foreach ($groupBy as $i => $fieldKey) {
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field || ! ($field['groupable'] ?? false)) {
                throw ValidationException::withMessages(["group_by.{$i}" => "Field {$fieldKey} is not groupable."]);
            }
        }

        $sort = $spec['sort'] ?? null;
        if ($sort) {
            $sortField = $sort['field'] ?? null;
            $validAliases = array_column($validatedColumns, 'alias');
            if (! in_array($sortField, $validAliases, true)) {
                throw ValidationException::withMessages(['sort.field' => 'Sort field must be a selected column alias.']);
            }
            $direction = strtolower($sort['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
            $sort = ['field' => $sortField, 'direction' => $direction];
        }

        $charts = $this->validateCharts($spec['charts'] ?? [], $validatedColumns);
        $kpis = $this->validateKpis($spec['kpis'] ?? [], $source);

        return [
            'source' => $sourceKey,
            'columns' => $validatedColumns,
            'group_by' => array_values($groupBy),
            'sort' => $sort,
            'charts' => $charts,
            'kpis' => $kpis,
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function toUiDefinition(CustomReportTemplate $template): array
    {
        $spec = $this->validateSpec($template->spec);
        $source = config("report_builder.sources.{$spec['source']}");

        $columns = array_map(function ($col) use ($source) {
            $field = $source['fields'][$col['field']];
            $type = $field['type'] ?? 'string';

            return [
                'key' => $col['alias'],
                'label' => $col['label'],
                'accessor' => null,
                'align' => in_array($type, ['money', 'number'], true) ? 'right' : 'left',
                'total' => (bool) $col['aggregate'],
            ];
        }, $spec['columns']);

        $charts = array_map(fn ($c) => [
            'type' => $c['type'],
            'title' => $c['title'] ?? null,
            'labelKey' => $c['label_key'],
            'valueKey' => $c['value_key'],
            'limit' => $c['limit'] ?? 5,
        ], $spec['charts']);

        return [
            'key' => 'custom-'.$template->id,
            'title' => $template->name,
            'subtitle' => $template->description ?? 'Custom report',
            'section' => 'Custom',
            'variant' => 'custom-builder',
            'templateId' => $template->id,
            'apiPath' => "/reports/builder/templates/{$template->id}/run",
            'dateColumn' => $source['default_date_column'] ? 'report_date' : null,
            'showDateRange' => ! empty($source['default_date_column']),
            'columns' => $columns,
            'charts' => $charts,
            'kpis' => $spec['kpis'],
            'footerTotals' => array_values(array_filter(array_map(
                fn ($c) => $c['aggregate'] ? $c['alias'] : null,
                $spec['columns']
            ))),
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    protected function buildQuery(User $user, array $spec, array $filters): Builder
    {
        $source = config("report_builder.sources.{$spec['source']}");
        $query = DB::table(DB::raw($source['table']));

        $requiredJoins = [];
        foreach ($spec['columns'] as $col) {
            $field = $source['fields'][$col['field']];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }
        foreach ($spec['group_by'] as $fieldKey) {
            $field = $source['fields'][$fieldKey];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }

        $alwaysJoin = array_flip($source['always_join'] ?? []);
        $leftJoins = array_flip($source['left_joins'] ?? []);
        foreach ($source['joins'] ?? [] as $joinKey => $joinDef) {
            if (! isset($requiredJoins[$joinKey]) && ! isset($alwaysJoin[$joinKey])) {
                continue;
            }
            $method = isset($leftJoins[$joinKey]) ? 'leftJoin' : 'join';
            $query->{$method}($joinDef[0], $joinDef[1], $joinDef[2], $joinDef[3]);
        }

        foreach ($source['base_where'] ?? [] as $where) {
            if ($where[1] === '=' && $where[2] === null) {
                $query->whereNull($where[0]);
            } else {
                $query->where($where[0], $where[1], $where[2]);
            }
        }

        if (! empty($source['org_column'])) {
            $query->where($source['org_column'], $user->organization_id);
        }

        $branchId = $filters['branch_id'] ?? null;
        if ($branchId && ! empty($source['branch_column'])) {
            $query->where($source['branch_column'], $branchId);
        }

        if (! empty($source['default_date_column'])) {
            $from = $filters['from_date'] ?? null;
            $to = $filters['to_date'] ?? null;
            if ($from) {
                $query->whereDate($source['default_date_column'], '>=', $from);
            }
            if ($to) {
                $query->whereDate($source['default_date_column'], '<=', $to);
            }
            $query->addSelect(DB::raw("DATE({$source['default_date_column']}) as report_date"));
        }

        $selects = [];
        foreach ($spec['columns'] as $col) {
            $field = $source['fields'][$col['field']];
            $expr = $field['expr'];
            if ($col['aggregate']) {
                $selects[] = DB::raw(strtoupper($col['aggregate'])."({$expr}) as `{$col['alias']}`");
            } else {
                $selects[] = DB::raw("{$expr} as `{$col['alias']}`");
            }
        }

        $query->select($selects);

        if ($spec['group_by']) {
            foreach ($spec['group_by'] as $fieldKey) {
                $field = $source['fields'][$fieldKey];
                $query->groupBy(DB::raw($field['expr']));
            }
        }

        if ($spec['sort']) {
            $query->orderBy($spec['sort']['field'], $spec['sort']['direction']);
        }

        return $query;
    }

    /**
     * @param  array<int, array<string, mixed>>  $charts
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<int, array<string, mixed>>
     */
    protected function validateCharts(array $charts, array $columns): array
    {
        $aliases = array_column($columns, 'alias');
        $valid = [];
        foreach ($charts as $i => $chart) {
            $type = $chart['type'] ?? 'bar';
            if (! in_array($type, config('report_builder.chart_types', []), true)) {
                continue;
            }
            $labelKey = $chart['label_key'] ?? null;
            $valueKey = $chart['value_key'] ?? null;
            if (! in_array($labelKey, $aliases, true) || ! in_array($valueKey, $aliases, true)) {
                throw ValidationException::withMessages(["charts.{$i}" => 'Chart fields must match column aliases.']);
            }
            $limit = max(1, (int) ($chart['limit'] ?? 5));
            $maxChartLimit = config('report_builder.max_chart_limit');
            if ($maxChartLimit !== null && $maxChartLimit > 0) {
                $limit = min($limit, (int) $maxChartLimit);
            }
            $valid[] = [
                'type' => $type,
                'title' => $chart['title'] ?? null,
                'label_key' => $labelKey,
                'value_key' => $valueKey,
                'limit' => $limit,
            ];
        }

        return $valid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $kpis
     * @param  array<string, mixed>  $source
     * @return array<int, array<string, mixed>>
     */
    protected function validateKpis(array $kpis, array $source): array
    {
        $valid = [];
        foreach ($kpis as $i => $kpi) {
            $fieldKey = $kpi['field'] ?? null;
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(["kpis.{$i}.field" => 'Invalid KPI field.']);
            }
            $aggregate = $kpi['aggregate'] ?? 'sum';
            if (! in_array($aggregate, $field['aggregates'] ?? [], true)) {
                throw ValidationException::withMessages(["kpis.{$i}.aggregate" => 'Invalid KPI aggregate.']);
            }
            $valid[] = [
                'id' => $kpi['id'] ?? "kpi_{$i}",
                'label' => $kpi['label'] ?? $field['label'],
                'field' => $fieldKey,
                'alias' => $kpi['alias'] ?? "{$fieldKey}_{$aggregate}",
                'aggregate' => $aggregate,
            ];
        }

        return $valid;
    }
}
