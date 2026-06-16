<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class AiEntitySchemaCatalog
{
    /** @return array<string, mixed> */
    public function all(): array
    {
        return config('ai_entity_schemas', []);
    }

    /** @return array<string, mixed>|null */
    public function forEntity(string $entity): ?array
    {
        $schema = config("ai_entity_schemas.{$entity}");
        if (! is_array($schema)) {
            return null;
        }

        return $schema;
    }

    /** @return list<string> */
    public function entityKeys(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string, mixed> */
    public function forEntityWithOptions(User $user, string $entity): ?array
    {
        $schema = $this->forEntity($entity);
        if (! $schema) {
            return null;
        }

        $fields = [];
        foreach ($schema['fields'] ?? [] as $name => $field) {
            $fields[$name] = $this->enrichField($user, $field);
        }

        return array_merge($schema, ['fields' => $fields]);
    }

    /** @return array<string, mixed> */
    public function summaryForContext(User $user, ?string $entity = null): array
    {
        if ($entity) {
            $one = $this->forEntityWithOptions($user, $entity);

            return $one ? [$entity => $this->compactSchema($one)] : [];
        }

        $out = [];
        foreach ($this->entityKeys() as $key) {
            $schema = $this->forEntity($key);
            if ($schema) {
                $out[$key] = $this->compactSchema($schema);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $field */
    protected function enrichField(User $user, array $field): array
    {
        if (! empty($field['options'])) {
            return $field;
        }

        if (! empty($field['relation'])) {
            $field['options'] = $this->loadRelationOptions($user, $field['relation']);
        }

        if (($field['type'] ?? '') === 'line_items' && ! empty($field['item_fields'])) {
            $items = [];
            foreach ($field['item_fields'] as $itemName => $itemField) {
                $items[$itemName] = $this->enrichField($user, $itemField);
            }
            $field['item_fields'] = $items;
        }

        return $field;
    }

    /** @param  array<string, mixed>  $relation
     * @return list<array{value: mixed, label: string}>
     */
    public function loadRelationOptions(User $user, array $relation): array
    {
        $table = (string) ($relation['table'] ?? '');
        $valueCol = (string) ($relation['value'] ?? 'id');
        $labelCol = (string) ($relation['label'] ?? 'name');

        if ($table === '' || ! DB::getSchemaBuilder()->hasTable($table)) {
            return [];
        }

        try {
            $query = DB::table($table);

            $joins = $relation['join'] ?? null;
            if ($joins) {
                $joinList = isset($joins[0]) && is_array($joins[0]) && array_key_exists(0, $joins[0])
                    ? $joins
                    : [$joins];
                foreach ($joinList as $join) {
                    if (is_array($join) && count($join) >= 4) {
                        [$joinTable, $first, $op, $second] = $join;
                        $query->join($joinTable, $first, $op, $second);
                    }
                }
            }

            $scope = $relation['scope'] ?? null;
        if ($scope === 'organization' && $this->tableHasColumn($table, 'organization_id')) {
            $query->where("{$table}.organization_id", $user->organization_id);
        }
        if ($scope === 'branch' && $this->tableHasColumn($table, 'branch_id') && $user->branch_id) {
            $query->where("{$table}.branch_id", $user->branch_id);
        }

        if ($table === 'products') {
            $query->whereNull("{$table}.deleted_at");
        }
        if ($table === 'customers') {
            $query->whereNull("{$table}.deleted_at");
        }

        foreach ($relation['where'] ?? [] as $where) {
            if (is_array($where) && count($where) === 3) {
                [$column, $operator, $value] = $where;
                $query->where($column, $operator, $value);
            }
        }

        $labelExpr = $relation['label_expr'] ?? null;
        $selectLabel = $labelExpr ? DB::raw("({$labelExpr}) as option_label") : "{$table}.{$labelCol} as option_label";

        return $query
            ->orderBy($labelCol)
            ->limit(200)
            ->get(["{$table}.{$valueCol} as option_value", $selectLabel])
            ->map(fn ($row) => [
                'value' => $row->option_value,
                'label' => (string) $row->option_label,
            ])
            ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    protected function tableHasColumn(string $table, string $column): bool
    {
        return DB::getSchemaBuilder()->hasColumn($table, $column);
    }

    /** @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function compactSchema(array $schema): array
    {
        $fields = [];
        foreach ($schema['fields'] ?? [] as $name => $field) {
            $fields[$name] = [
                'label' => $field['label'] ?? $name,
                'type' => $field['type'] ?? 'string',
                'required' => (bool) ($field['required'] ?? false),
                'auto_generated' => (bool) ($field['auto_generated'] ?? false),
                'important' => (bool) ($field['important'] ?? false),
                'relation' => isset($field['relation']['table'])
                    ? ($field['relation']['table'].'.'.($field['relation']['label'] ?? 'name'))
                    : null,
                'hint' => $field['hint'] ?? null,
            ];
        }

        return [
            'label' => $schema['label'] ?? '',
            'module' => $schema['module'] ?? null,
            'path' => $schema['path'] ?? null,
            'create_action' => $schema['create_action'] ?? null,
            'fields' => $fields,
        ];
    }
}
