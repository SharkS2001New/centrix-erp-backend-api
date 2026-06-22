<?php

namespace App\Services\Ai;

use App\Models\User;

class AiFormSpecBuilder
{
    public function __construct(
        protected AiEntitySchemaCatalog $schemas,
    ) {}

    /** @param  array<string, mixed>  $action
     * @return array<string, mixed>|null
     */
    public function forAction(User $user, array $action, ?string $pathname = null): ?array
    {
        $type = (string) ($action['type'] ?? '');
        $entity = $this->entityForAction($type, $pathname);
        if (! $entity) {
            return null;
        }

        $schema = $this->schemas->forEntityWithOptions($user, $entity);
        if (! $schema) {
            return null;
        }

        $params = is_array($action['params'] ?? null) ? $action['params'] : [];
        $fields = [];
        $hints = [];
        $fieldNames = $schema['ai_form_fields'] ?? array_keys($schema['fields'] ?? []);

        foreach ($fieldNames as $name) {
            $field = $schema['fields'][$name] ?? null;
            if (! is_array($field)) {
                continue;
            }

            if (($field['type'] ?? '') === 'line_items') {
                $hints[] = 'Line items: tell me product codes and quantities in chat, or use Sales → Orders.';

                continue;
            }

            $isAuto = ! empty($field['auto_generated']) && empty($params[$name]);
            $isImportant = ! empty($field['required']) || ! empty($field['important']);

            if ($isAuto && ! $isImportant) {
                $hints[] = ($field['label'] ?? $name).': '.($field['hint'] ?? 'Auto-generated if left blank');

                continue;
            }

            if ($isAuto) {
                $field['read_only'] = true;
                $field['placeholder'] = $field['hint'] ?? 'Auto-generated if left blank';
            }

            if (array_key_exists($name, $params)) {
                $field['value'] = $params[$name];
            }

            $fields[] = array_merge(['name' => $name], $field);
        }

        return [
            'entity' => $entity,
            'action_type' => $type,
            'title' => $schema['label'] ?? $type,
            'fields' => $fields,
            'hints' => $hints,
            'submit_label' => match ($type) {
                'record_customer_payment' => 'Confirm & record payment',
                'create_supplier' => 'Confirm & add supplier',
                'create_customer' => 'Confirm & add customer',
                default => 'Confirm & create',
            },
        ];
    }

    protected function entityForAction(string $type, ?string $pathname = null): ?string
    {
        foreach (config('ai_entity_schemas', []) as $entity => $schema) {
            if (($schema['create_action'] ?? null) === $type) {
                return $entity;
            }
        }

        $pathEntity = $this->entityFromPath($pathname);
        if ($pathEntity && $this->createActionForEntity($pathEntity) === $type) {
            return $pathEntity;
        }

        return match ($type) {
            'create_held_order' => 'sales_order',
            default => null,
        };
    }

    protected function entityFromPath(?string $pathname): ?string
    {
        $path = '/'.trim((string) $pathname, '/');
        if ($path === '/') {
            return null;
        }

        $bestEntity = null;
        $bestLength = -1;

        foreach (config('ai_entity_schemas', []) as $entity => $schema) {
            $entityPath = (string) ($schema['path'] ?? '');
            if ($entityPath === '') {
                continue;
            }

            $prefix = rtrim($entityPath, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                $length = strlen($prefix);
                if ($length > $bestLength) {
                    $bestEntity = $entity;
                    $bestLength = $length;
                }
            }
        }

        return $bestEntity;
    }

    protected function createActionForEntity(string $entity): ?string
    {
        $schema = config("ai_entity_schemas.{$entity}");

        return is_array($schema) ? ($schema['create_action'] ?? null) : null;
    }
}
