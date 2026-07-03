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
    public function resolveWorkspaceId(?string $workspaceId): string
    {
        $map = config('report_builder.workspace_source_modules', []);

        return ($workspaceId && isset($map[$workspaceId])) ? $workspaceId : 'backoffice';
    }

    /** @return list<string> */
    public function allowedSourceModules(?string $workspaceId): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $map = config('report_builder.workspace_source_modules', []);

        return array_values($map[$workspaceId] ?? []);
    }

    /** @return list<string> */
    public function allowedSourceKeys(?string $workspaceId): array
    {
        $allowedModules = array_flip($this->allowedSourceModules($workspaceId));
        $keys = [];
        foreach (config('report_builder.sources', []) as $key => $source) {
            $module = $source['module'] ?? 'General';
            if (isset($allowedModules[$module])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function assertSourceAllowed(string $sourceKey, ?string $workspaceId): void
    {
        if (! in_array($sourceKey, $this->allowedSourceKeys($workspaceId), true)) {
            throw ValidationException::withMessages([
                'source' => 'This data source is not available in the current workspace.',
            ]);
        }
    }

    /** @return list<string> */
    public function templateSpecSources(array $spec): array
    {
        if (! empty($spec['sources']) && is_array($spec['sources'])) {
            return array_values(array_unique(array_filter($spec['sources'])));
        }

        return isset($spec['source']) ? [$spec['source']] : [];
    }

    /**
     * Hub/sidebar metadata for a saved custom report template.
     *
     * @return array{
     *   category_id: string,
     *   category_label: string,
     *   report_module: string|null,
     *   primary_source: string|null,
     *   sources: list<string>
     * }
     */
    public function templateListMeta(array $spec): array
    {
        $sources = $this->templateSpecSources($spec);
        $primary = $spec['source'] ?? $sources[0] ?? null;
        $builderModule = $primary
            ? (config("report_builder.sources.{$primary}.module") ?? 'General')
            : 'General';

        return [
            'category_id' => $this->builderModuleToCategoryId($builderModule),
            'category_label' => $this->builderModuleToCategoryLabel($builderModule),
            'report_module' => $this->builderModuleToReportModule($builderModule),
            'primary_source' => $primary,
            'sources' => $sources,
        ];
    }

    protected function builderModuleToCategoryId(string $module): string
    {
        return match ($module) {
            'Sales' => 'sales',
            'Inventory' => 'inventory',
            'Purchasing' => 'purchases',
            'Accounting' => 'finance',
            'HR' => 'hr',
            default => 'other',
        };
    }

    protected function builderModuleToCategoryLabel(string $module): string
    {
        return match ($module) {
            'Sales' => 'Sales Reports',
            'Inventory' => 'Inventory Reports',
            'Purchasing' => 'Purchases Reports',
            'Accounting' => 'Finance & Accounting',
            'HR' => 'HR Reports',
            default => 'Other Reports',
        };
    }

    protected function builderModuleToReportModule(string $module): ?string
    {
        return match ($module) {
            'Sales' => 'sales.reports',
            'Inventory' => 'inventory.reports',
            'Purchasing' => 'customers_suppliers.reports',
            'Accounting' => 'accounting.reports',
            'HR' => 'hr_payroll.reports',
            default => null,
        };
    }

    public function schema(?string $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $allowedModules = array_flip($this->allowedSourceModules($workspaceId));
        $sources = config('report_builder.sources', []);
        $out = [];
        $modules = [];
        foreach ($sources as $key => $source) {
            $module = $source['module'] ?? 'General';
            if (! isset($allowedModules[$module])) {
                continue;
            }

            $fields = [];
            $fieldKeys = array_keys($source['fields']);
            $hasProductName = in_array('product_name', $fieldKeys, true);
            foreach ($source['fields'] as $fieldKey => $field) {
                if ($hasProductName && $fieldKey === 'product_code') {
                    continue;
                }
                $fields[] = [
                    'key' => $fieldKey,
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'groupable' => (bool) ($field['groupable'] ?? false),
                    'aggregates' => $field['aggregates'] ?? [],
                ];
            }
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
            'workspace_id' => $workspaceId,
            'sources' => $out,
            'modules' => array_map(
                fn (string $name, int $count) => ['name' => $name, 'source_count' => $count],
                array_keys($modules),
                array_values($modules)
            ),
            'blend_dimensions' => $this->blendDimensionsForSchema(),
            'max_sources' => (int) config('report_builder.max_sources', 4),
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
    public function run(User $user, array $spec, array $filters = [], ?string $workspaceId = null): LengthAwarePaginator
    {
        $spec = $this->validateSpec($spec, $workspaceId);
        $query = $this->buildQuery($user, $spec, $filters);
        $perPage = min((int) ($filters['per_page'] ?? 50), 200);

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function validateSpec(array $spec, ?string $workspaceId = null): array
    {
        $sources = $this->resolveSources($spec);
        if ($sources === []) {
            throw ValidationException::withMessages(['source' => 'Select at least one data source.']);
        }

        $maxSources = (int) config('report_builder.max_sources', 4);
        if (count($sources) > $maxSources) {
            throw ValidationException::withMessages(['sources' => "Select at most {$maxSources} data sources."]);
        }

        foreach ($sources as $sourceKey) {
            if (! config("report_builder.sources.{$sourceKey}")) {
                throw ValidationException::withMessages(['sources' => "Invalid data source: {$sourceKey}."]);
            }
            $this->assertSourceAllowed($sourceKey, $workspaceId);
        }

        $primarySource = $spec['source'] ?? $sources[0];
        if (! in_array($primarySource, $sources, true)) {
            $primarySource = $sources[0];
        }

        $columns = $spec['columns'] ?? [];
        if (! is_array($columns) || count($columns) < 1) {
            throw ValidationException::withMessages(['columns' => 'Select at least one column.']);
        }

        $maxColumns = config('report_builder.max_columns');
        if ($maxColumns !== null && $maxColumns > 0 && count($columns) > (int) $maxColumns) {
            throw ValidationException::withMessages(['columns' => 'Too many columns.']);
        }

        $isMultiSource = count($sources) > 1;
        $blendBy = $spec['blend_by'] ?? null;

        if ($isMultiSource && $blendBy) {
            return $this->validateBlendedSpec($spec, $sources, $primarySource, $columns, $blendBy);
        }

        if ($isMultiSource) {
            return $this->validateJoinedSpec($spec, $sources, $primarySource, $columns);
        }

        return $this->validateSingleSourceSpec($spec, $primarySource, $columns);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function toUiDefinition(CustomReportTemplate $template, ?string $workspaceId = null): array
    {
        $spec = $this->validateSpec($template->spec, $workspaceId);
        $isBlended = count($spec['sources']) > 1 && ! empty($spec['blend_by']);
        $isJoined = count($spec['sources']) > 1 && empty($spec['blend_by']);

        $columns = [];
        foreach ($spec['columns'] as $col) {
            $source = config("report_builder.sources.{$col['source']}");
            $field = $source['fields'][$col['field']];
            $type = $field['type'] ?? 'string';

            $columns[] = [
                'key' => $col['alias'],
                'label' => $col['label'],
                'accessor' => null,
                'align' => in_array($type, ['money', 'number'], true) ? 'right' : 'left',
                'total' => (bool) $col['aggregate'],
            ];
        }

        $charts = array_map(fn ($c) => [
            'type' => $c['type'],
            'title' => $c['title'] ?? null,
            'labelKey' => $c['label_key'],
            'valueKey' => $c['value_key'],
            'limit' => $c['limit'] ?? 5,
        ], $spec['charts']);

        $dateColumn = null;
        if ($isBlended && $spec['blend_by']) {
            $dateColumn = config("report_builder.blend_dimensions.{$spec['blend_by']}.output_alias");
        } elseif ($isJoined || count($spec['sources']) === 1) {
            $source = config("report_builder.sources.{$spec['source']}");
            $dateColumn = $source['default_date_column'] ? 'report_date' : null;
        }

        return [
            'key' => 'custom-'.$template->id,
            'title' => $template->name,
            'subtitle' => $template->description ?? 'Custom report',
            'section' => 'Custom',
            'variant' => 'custom-builder',
            'templateId' => $template->id,
            'apiPath' => "/reports/builder/templates/{$template->id}/run",
            'dateColumn' => $dateColumn,
            'showDateRange' => $dateColumn !== null,
            'columns' => $columns,
            'charts' => $charts,
            'kpis' => $spec['kpis'],
            'footerTotals' => array_values(array_filter(array_map(
                fn ($c) => $c['aggregate'] ? $c['alias'] : null,
                $spec['columns']
            ))),
        ];
    }

    /** @return list<array{key: string, label: string, output_alias: string, sources: list<string>}> */
    protected function blendDimensionsForSchema(): array
    {
        $out = [];
        foreach (config('report_builder.blend_dimensions', []) as $key => $def) {
            $out[] = [
                'key' => $key,
                'label' => $def['label'] ?? $key,
                'output_alias' => $def['output_alias'] ?? $key,
                'sources' => array_keys($def['sources'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return list<string>
     */
    protected function resolveSources(array $spec): array
    {
        $primary = $spec['source'] ?? null;
        $sources = is_array($spec['sources'] ?? null) ? $spec['sources'] : [];

        if ($primary && ! in_array($primary, $sources, true)) {
            array_unshift($sources, $primary);
        }

        foreach ($spec['columns'] ?? [] as $col) {
            if (! empty($col['source']) && ! in_array($col['source'], $sources, true)) {
                $sources[] = $col['source'];
            }
        }

        $sources = array_values(array_unique(array_filter($sources)));

        if ($sources === [] && $primary) {
            return [$primary];
        }

        return $sources;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $sources
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    protected function validateBlendedSpec(
        array $spec,
        array $sources,
        string $primarySource,
        array $columns,
        ?string $blendBy
    ): array {
        if (! $blendBy) {
            throw ValidationException::withMessages([
                'blend_by' => 'Choose how to combine rows when using multiple data sources.',
            ]);
        }

        $blendDef = config("report_builder.blend_dimensions.{$blendBy}");
        if (! $blendDef) {
            throw ValidationException::withMessages(['blend_by' => 'Invalid combine-by dimension.']);
        }

        foreach ($sources as $sourceKey) {
            if (! isset($blendDef['sources'][$sourceKey])) {
                throw ValidationException::withMessages([
                    'blend_by' => "The selected dimension cannot combine {$sourceKey} with the other sources.",
                ]);
            }
        }

        $groupBy = $spec['group_by'] ?? [];
        if (is_array($groupBy) && count($groupBy) > 0) {
            throw ValidationException::withMessages([
                'group_by' => 'Group by is not used when blending multiple sources. Use combine-by instead.',
            ]);
        }

        $validatedColumns = [];
        $outputKeys = [];
        $sourcesWithColumns = [];

        foreach ($columns as $i => $col) {
            $sourceKey = $col['source'] ?? $primarySource;
            if (! in_array($sourceKey, $sources, true)) {
                throw ValidationException::withMessages(["columns.{$i}.source" => 'Column source must be one of the selected sources.']);
            }

            $source = config("report_builder.sources.{$sourceKey}");
            $fieldKey = $col['field'] ?? null;
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(["columns.{$i}.field" => "Unknown field: {$fieldKey}"]);
            }

            $aggregate = $col['aggregate'] ?? null;
            if (! $aggregate) {
                $aggregate = $field['aggregates'][0] ?? 'sum';
            }
            if (! in_array($aggregate, $field['aggregates'] ?? [], true)) {
                throw ValidationException::withMessages(["columns.{$i}.aggregate" => "Field {$fieldKey} cannot use aggregate {$aggregate}."]);
            }

            $alias = $col['alias'] ?? "{$sourceKey}_{$fieldKey}_{$aggregate}";
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $alias)) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Invalid column alias.']);
            }
            if (isset($outputKeys[$alias])) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Duplicate column alias.']);
            }
            $outputKeys[$alias] = true;
            $sourcesWithColumns[$sourceKey] = true;

            $label = $col['label'] ?? $field['label'];
            $sourceLabel = $source['label'] ?? $sourceKey;
            if (! str_contains(strtolower($label), strtolower($sourceLabel))) {
                $label = "{$sourceLabel}: {$label}";
            }

            $validatedColumns[] = [
                'source' => $sourceKey,
                'field' => $fieldKey,
                'label' => $label,
                'alias' => $alias,
                'aggregate' => $aggregate,
            ];
        }

        $sort = $this->validateSort($spec['sort'] ?? null, $validatedColumns);
        $charts = $this->validateCharts($spec['charts'] ?? [], $validatedColumns);

        return [
            'source' => $primarySource,
            'sources' => $sources,
            'blend_by' => $blendBy,
            'mode' => 'blend',
            'columns' => $validatedColumns,
            'group_by' => [],
            'sort' => $sort,
            'charts' => $charts,
            'kpis' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $sources
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    protected function validateJoinedSpec(
        array $spec,
        array $sources,
        string $primarySource,
        array $columns,
    ): array {
        $groupBy = $this->normalizeGroupByEntries($spec['group_by'] ?? [], $primarySource);
        $maxGroupBy = config('report_builder.max_group_by');
        if ($maxGroupBy !== null && $maxGroupBy > 0 && count($groupBy) > (int) $maxGroupBy) {
            throw ValidationException::withMessages(['group_by' => 'Too many group-by fields.']);
        }

        $validatedColumns = [];
        $outputKeys = [];

        foreach ($columns as $i => $col) {
            $sourceKey = $col['source'] ?? $primarySource;
            if (! in_array($sourceKey, $sources, true)) {
                throw ValidationException::withMessages(["columns.{$i}.source" => 'Column source must be one of the selected sources.']);
            }

            $source = config("report_builder.sources.{$sourceKey}");
            $fieldKey = $col['field'] ?? null;
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field) {
                throw ValidationException::withMessages(["columns.{$i}.field" => "Unknown field: {$fieldKey}"]);
            }

            $aggregate = $col['aggregate'] ?? null;
            if ($groupBy) {
                $inGroup = collect($groupBy)->contains(
                    fn ($g) => $g['source'] === $sourceKey && $g['field'] === $fieldKey
                );
                if (! $inGroup) {
                    $aggregate = $aggregate ?: ($field['aggregates'][0] ?? 'sum');
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
            if (count($sources) > 1 && ! $groupBy) {
                $alias = $col['alias'] ?? "{$sourceKey}_{$fieldKey}".($aggregate ? "_{$aggregate}" : '');
            }
            if (! preg_match('/^[a-z][a-z0-9_]*$/', $alias)) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Invalid column alias.']);
            }
            if (isset($outputKeys[$alias])) {
                throw ValidationException::withMessages(["columns.{$i}.alias" => 'Duplicate column alias.']);
            }
            $outputKeys[$alias] = true;

            $label = $col['label'] ?? $field['label'];
            if (count($sources) > 1) {
                $sourceLabel = $source['label'] ?? $sourceKey;
                if (! str_contains(strtolower($label), strtolower($sourceLabel))) {
                    $label = "{$sourceLabel}: {$label}";
                }
            }

            $validatedColumns[] = [
                'source' => $sourceKey,
                'field' => $fieldKey,
                'label' => $label,
                'alias' => $alias,
                'aggregate' => $aggregate,
            ];
        }

        foreach ($groupBy as $i => $entry) {
            $sourceKey = $entry['source'];
            $fieldKey = $entry['field'];
            if (! in_array($sourceKey, $sources, true)) {
                throw ValidationException::withMessages(["group_by.{$i}" => 'Invalid group-by source.']);
            }
            $source = config("report_builder.sources.{$sourceKey}");
            $field = $source['fields'][$fieldKey] ?? null;
            if (! $field || ! ($field['groupable'] ?? false)) {
                throw ValidationException::withMessages(["group_by.{$i}" => "Field {$fieldKey} is not groupable."]);
            }
        }

        $this->assertSourcesAreJoinable($primarySource, $this->collectReferencedSources($validatedColumns, $groupBy));

        $sort = $this->validateSort($spec['sort'] ?? null, $validatedColumns);
        $charts = $this->validateCharts($spec['charts'] ?? [], $validatedColumns);
        $primaryConfig = config("report_builder.sources.{$primarySource}");

        return [
            'source' => $primarySource,
            'sources' => $sources,
            'blend_by' => null,
            'mode' => 'join',
            'columns' => $validatedColumns,
            'group_by' => $groupBy,
            'sort' => $sort,
            'charts' => $charts,
            'kpis' => $this->validateKpis($spec['kpis'] ?? [], $primaryConfig),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     * @param  array<int, array{source: string, field: string}>  $groupBy
     * @return list<string>
     */
    protected function collectReferencedSources(array $columns, array $groupBy): array
    {
        $keys = array_column($columns, 'source');
        foreach ($groupBy as $entry) {
            $keys[] = $entry['source'];
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @param  list<string>  $targetSources
     */
    protected function assertSourcesAreJoinable(string $primarySource, array $targetSources): void
    {
        $graph = $this->sourceLinkGraph();
        foreach ($targetSources as $targetSource) {
            if ($targetSource === $primarySource) {
                continue;
            }
            if ($this->findLinkPath($graph, $primarySource, $targetSource) === null) {
                throw ValidationException::withMessages([
                    'sources' => $this->unjoinableSourcesMessage($primarySource, $targetSource),
                ]);
            }
        }
    }

    protected function sourceLabel(string $sourceKey): string
    {
        return config("report_builder.sources.{$sourceKey}.label") ?? $sourceKey;
    }

    protected function unjoinableSourcesMessage(string $primarySource, string $targetSource): string
    {
        $primaryLabel = $this->sourceLabel($primarySource);
        $targetLabel = $this->sourceLabel($targetSource);

        return "{$primaryLabel} and {$targetLabel} cannot be combined in one joined report. "
            .'Try adding a related source that links them (for example, sale line items between sales and products), '
            .'or use side-by-side metrics when that option is available.';
    }

    /**
     * @param  mixed  $groupBy
     * @return array<int, array{source: string, field: string}>
     */
    protected function normalizeGroupByEntries(mixed $groupBy, string $defaultSource): array
    {
        if (! is_array($groupBy)) {
            return [];
        }

        $out = [];
        foreach ($groupBy as $entry) {
            if (is_string($entry)) {
                $out[] = ['source' => $defaultSource, 'field' => $entry];
            } elseif (is_array($entry) && ! empty($entry['field'])) {
                $out[] = [
                    'source' => $entry['source'] ?? $defaultSource,
                    'field' => $entry['field'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    protected function sourceLinkGraph(): array
    {
        static $graph = null;
        if ($graph !== null) {
            return $graph;
        }

        $graph = [];
        foreach (config('report_builder.sources', []) as $fromKey => $fromSource) {
            foreach ($fromSource['joins'] ?? [] as $toKey => $joinDef) {
                $toSource = config("report_builder.sources.{$toKey}");
                if (! $toSource) {
                    continue;
                }

                $left = in_array($toKey, $fromSource['left_joins'] ?? [], true);
                $graph[$fromKey][$toKey] = [
                    'join_key' => "{$fromKey}_{$toKey}",
                    'table' => $joinDef[0],
                    'first' => $joinDef[1],
                    'op' => $joinDef[2],
                    'second' => $joinDef[3],
                    'left' => $left,
                ];
                $graph[$toKey][$fromKey] = [
                    'join_key' => "{$fromKey}_{$toKey}",
                    'table' => $fromSource['table'],
                    'first' => $joinDef[3],
                    'op' => $joinDef[2],
                    'second' => $joinDef[1],
                    'left' => $left,
                ];
            }
        }

        foreach (config('report_builder.source_links.extra_edges', []) as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];
            $graph[$from][$to] = $edge;
            if (! isset($graph[$to][$from])) {
                $toTable = config("report_builder.sources.{$to}.table") ?? $edge['table'];
                $graph[$to][$from] = [
                    'join_key' => $edge['join_key'],
                    'table' => $toTable,
                    'first' => $edge['second'],
                    'op' => $edge['op'],
                    'second' => $edge['first'],
                    'left' => $edge['left'] ?? false,
                ];
            }
        }

        return $graph;
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $graph
     * @return list<array<string, mixed>>|null
     */
    protected function findLinkPath(array $graph, string $from, string $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $visited = [$from => true];
        $queue = [[$from, []]];

        while ($queue !== []) {
            [$node, $path] = array_shift($queue);
            foreach ($graph[$node] ?? [] as $next => $edge) {
                if (isset($visited[$next])) {
                    continue;
                }
                $nextPath = [...$path, $edge];
                if ($next === $to) {
                    return $nextPath;
                }
                $visited[$next] = true;
                $queue[] = [$next, $nextPath];
            }
        }

        return null;
    }

    protected function tableAliasFromRef(string $table): string
    {
        if (preg_match('/\bas\s+(\w+)\s*$/i', $table, $matches)) {
            return $matches[1];
        }

        return $table;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $spec
     * @return array<string, bool>
     */
    protected function collectRequiredJoinsForSource(
        string $sourceKey,
        array $source,
        array $spec,
        string $primaryKey
    ): array {
        $requiredJoins = [];
        foreach ($source['always_join'] ?? [] as $joinKey) {
            $requiredJoins[$joinKey] = true;
        }

        foreach ($spec['columns'] as $col) {
            if (($col['source'] ?? $primaryKey) !== $sourceKey) {
                continue;
            }
            $field = $source['fields'][$col['field']];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }

        foreach ($spec['group_by'] as $entry) {
            if (($entry['source'] ?? $primaryKey) !== $sourceKey) {
                continue;
            }
            $field = $source['fields'][$entry['field']];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }

        return $requiredJoins;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    protected function applySecondarySourceFilters(Builder $query, array $source): void
    {
        foreach ($source['base_where'] ?? [] as $where) {
            if ($where[1] === '=' && $where[2] === null) {
                $query->whereNull($where[0]);
            } else {
                $query->where($where[0], $where[1], $where[2]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    protected function validateSingleSourceSpec(array $spec, string $sourceKey, array $columns): array
    {
        $source = config("report_builder.sources.{$sourceKey}");

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
            $colSource = $col['source'] ?? $sourceKey;
            if ($colSource !== $sourceKey) {
                throw ValidationException::withMessages([
                    "columns.{$i}.source" => 'Use multiple data sources to mix columns from different modules.',
                ]);
            }

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
                'source' => $sourceKey,
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

        $sort = $this->validateSort($spec['sort'] ?? null, $validatedColumns);
        $charts = $this->validateCharts($spec['charts'] ?? [], $validatedColumns);
        $kpis = $this->validateKpis($spec['kpis'] ?? [], $source);

        return [
            'source' => $sourceKey,
            'sources' => [$sourceKey],
            'blend_by' => null,
            'mode' => 'single',
            'columns' => $validatedColumns,
            'group_by' => array_values($groupBy),
            'sort' => $sort,
            'charts' => $charts,
            'kpis' => $kpis,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $sort
     * @param  array<int, array<string, mixed>>  $validatedColumns
     * @return array{field: string, direction: string}|null
     */
    protected function validateSort(?array $sort, array $validatedColumns): ?array
    {
        if (! $sort) {
            return null;
        }

        $sortField = $sort['field'] ?? null;
        $validAliases = array_column($validatedColumns, 'alias');
        if (! in_array($sortField, $validAliases, true)) {
            throw ValidationException::withMessages(['sort.field' => 'Sort field must be a selected column alias.']);
        }

        return [
            'field' => $sortField,
            'direction' => strtolower($sort['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    protected function buildQuery(User $user, array $spec, array $filters): Builder
    {
        $mode = $spec['mode'] ?? (count($spec['sources']) > 1 ? 'join' : 'single');

        if ($mode === 'blend') {
            return $this->buildBlendedQuery($user, $spec, $filters);
        }

        if ($mode === 'join') {
            return $this->buildJoinedMultiSourceQuery($user, $spec, $filters);
        }

        return $this->buildSingleSourceQuery($user, $spec, $filters);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    protected function buildSingleSourceQuery(User $user, array $spec, array $filters): Builder
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
            $key = is_array($fieldKey) ? ($fieldKey['field'] ?? null) : $fieldKey;
            if (! $key) {
                continue;
            }
            $field = $source['fields'][$key];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }

        $this->applyConfiguredJoins($query, $source, $requiredJoins);
        $this->applySourceFilters($query, $source, $user, $filters, $source['default_date_column'] ?? null);

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
                $key = is_array($fieldKey) ? ($fieldKey['field'] ?? null) : $fieldKey;
                if (! $key) {
                    continue;
                }
                $field = $source['fields'][$key];
                $query->groupBy(DB::raw($field['expr']));
            }
        }

        if ($spec['sort']) {
            $query->orderBy($spec['sort']['field'], $spec['sort']['direction']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    protected function buildJoinedMultiSourceQuery(User $user, array $spec, array $filters): Builder
    {
        $primaryKey = $spec['source'];
        $primary = config("report_builder.sources.{$primaryKey}");
        $query = DB::table(DB::raw($primary['table']));
        $graph = $this->sourceLinkGraph();

        $referencedSources = $this->collectReferencedSources($spec['columns'], $spec['group_by']);
        $appliedJoinKeys = [];
        $joinedAliases = [$this->tableAliasFromRef($primary['table']) => true];

        foreach ($referencedSources as $targetKey) {
            if ($targetKey === $primaryKey) {
                continue;
            }
            $path = $this->findLinkPath($graph, $primaryKey, $targetKey);
            if ($path === null) {
                throw ValidationException::withMessages([
                    'sources' => $this->unjoinableSourcesMessage($primaryKey, $targetKey),
                ]);
            }
            foreach ($path as $edge) {
                if (isset($appliedJoinKeys[$edge['join_key']])) {
                    continue;
                }
                $alias = $this->tableAliasFromRef($edge['table']);
                if (isset($joinedAliases[$alias])) {
                    $appliedJoinKeys[$edge['join_key']] = true;

                    continue;
                }
                $appliedJoinKeys[$edge['join_key']] = true;
                $joinedAliases[$alias] = true;
                $method = ! empty($edge['left']) ? 'leftJoin' : 'join';
                $query->{$method}($edge['table'], $edge['first'], $edge['op'], $edge['second']);
            }
        }

        foreach ($referencedSources as $sourceKey) {
            $sourceConfig = config("report_builder.sources.{$sourceKey}");
            $requiredJoins = $this->collectRequiredJoinsForSource($sourceKey, $sourceConfig, $spec, $primaryKey);
            $this->applyConfiguredJoins($query, $sourceConfig, $requiredJoins, [], $joinedAliases);
        }

        $this->applySourceFilters($query, $primary, $user, $filters, $primary['default_date_column'] ?? null);

        foreach ($referencedSources as $sourceKey) {
            if ($sourceKey === $primaryKey) {
                continue;
            }
            $this->applySecondarySourceFilters($query, config("report_builder.sources.{$sourceKey}"));
        }

        $selects = [];
        foreach ($spec['columns'] as $col) {
            $source = config("report_builder.sources.{$col['source']}");
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
            foreach ($spec['group_by'] as $entry) {
                $source = config("report_builder.sources.{$entry['source']}");
                $field = $source['fields'][$entry['field']];
                $query->groupBy(DB::raw($field['expr']));
            }
        }

        if ($spec['sort']) {
            $query->orderBy($spec['sort']['field'], $spec['sort']['direction']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $filters
     */
    protected function buildBlendedQuery(User $user, array $spec, array $filters): Builder
    {
        $blendKey = $spec['blend_by'];
        $blendDef = config("report_builder.blend_dimensions.{$blendKey}");
        $outputAlias = $blendDef['output_alias'];

        $columnsBySource = [];
        foreach ($spec['columns'] as $col) {
            $columnsBySource[$col['source']][] = $col;
        }

        $subqueries = [];
        $keyUnionParts = [];
        foreach ($spec['sources'] as $sourceKey) {
            if (empty($columnsBySource[$sourceKey])) {
                continue;
            }
            $sub = $this->buildSourceAggregateSubquery(
                $user,
                $sourceKey,
                $columnsBySource[$sourceKey],
                $blendKey,
                $filters
            );
            $subqueries[$sourceKey] = $sub;
            $keyUnionParts[] = DB::query()->fromSub($sub, 'keys_'.$sourceKey)->select('blend_key');
        }

        if ($keyUnionParts === []) {
            throw ValidationException::withMessages(['columns' => 'No columns to blend.']);
        }

        $keysQuery = $keyUnionParts[0];
        for ($i = 1; $i < count($keyUnionParts); $i++) {
            $keysQuery = $keysQuery->union($keyUnionParts[$i]);
        }

        $keysSub = DB::query()->fromSub($keysQuery, 'all_blend_keys')->select('blend_key')->distinct();
        $query = DB::query()->fromSub($keysSub, 'k');

        foreach ($subqueries as $sourceKey => $sub) {
            $alias = 'src_'.$sourceKey;
            $query->leftJoinSub($sub, $alias, function ($join) use ($alias) {
                $join->on('k.blend_key', '=', "{$alias}.blend_key");
            });
        }

        $selects = [DB::raw("k.blend_key as `{$outputAlias}`")];
        foreach ($spec['columns'] as $col) {
            $alias = 'src_'.$col['source'];
            $selects[] = DB::raw("{$alias}.`{$col['alias']}` as `{$col['alias']}`");
        }

        $query->select($selects);

        if ($spec['sort']) {
            $query->orderBy($spec['sort']['field'], $spec['sort']['direction']);
        } else {
            $query->orderBy($outputAlias, 'asc');
        }

        return $query;
    }

    /**
     * @param  array<int, array<string, mixed>>  $columns
     */
    protected function buildSourceAggregateSubquery(
        User $user,
        string $sourceKey,
        array $columns,
        string $blendKey,
        array $filters
    ): Builder {
        $source = config("report_builder.sources.{$sourceKey}");
        $blendSource = config("report_builder.blend_dimensions.{$blendKey}.sources.{$sourceKey}");

        $query = DB::table(DB::raw($source['table']));

        $requiredJoins = [];
        foreach ($source['always_join'] ?? [] as $joinKey) {
            $requiredJoins[$joinKey] = true;
        }
        foreach ($blendSource['joins'] ?? [] as $joinKey) {
            $requiredJoins[$joinKey] = true;
        }
        foreach ($columns as $col) {
            $field = $source['fields'][$col['field']];
            if (! empty($field['requires_join'])) {
                $requiredJoins[$field['requires_join']] = true;
            }
        }

        $this->applyConfiguredJoins($query, $source, $requiredJoins, $blendSource['left_joins'] ?? []);
        $this->applySourceFilters($query, $source, $user, $filters, $blendSource['date_filter'] ?? null);

        $blendExpr = $blendSource['expr'];
        $selects = [DB::raw("{$blendExpr} as blend_key")];
        foreach ($columns as $col) {
            $field = $source['fields'][$col['field']];
            $expr = $field['expr'];
            $selects[] = DB::raw(strtoupper($col['aggregate'])."({$expr}) as `{$col['alias']}`");
        }

        return $query->select($selects)->groupBy(DB::raw($blendExpr));
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, bool>  $requiredJoins
     * @param  list<string>  $extraLeftJoins
     * @param  array<string, bool>|null  $joinedAliases
     */
    protected function applyConfiguredJoins(
        Builder $query,
        array $source,
        array $requiredJoins,
        array $extraLeftJoins = [],
        ?array &$joinedAliases = null,
    ): void {
        $alwaysJoin = array_flip($source['always_join'] ?? []);
        $leftJoins = array_flip(array_merge($source['left_joins'] ?? [], $extraLeftJoins));

        foreach ($source['joins'] ?? [] as $joinKey => $joinDef) {
            if (! isset($requiredJoins[$joinKey]) && ! isset($alwaysJoin[$joinKey])) {
                continue;
            }
            $alias = $this->tableAliasFromRef($joinDef[0]);
            if ($joinedAliases !== null && isset($joinedAliases[$alias])) {
                continue;
            }
            if ($joinedAliases !== null) {
                $joinedAliases[$alias] = true;
            }
            $method = isset($leftJoins[$joinKey]) ? 'leftJoin' : 'join';
            $query->{$method}($joinDef[0], $joinDef[1], $joinDef[2], $joinDef[3]);
        }
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $filters
     */
    protected function applySourceFilters(
        Builder $query,
        array $source,
        User $user,
        array $filters,
        ?string $dateColumn
    ): void {
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

        if ($dateColumn) {
            $from = $filters['from_date'] ?? null;
            $to = $filters['to_date'] ?? null;
            if ($from) {
                $query->whereDate($dateColumn, '>=', $from);
            }
            if ($to) {
                $query->whereDate($dateColumn, '<=', $to);
            }
        }
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
