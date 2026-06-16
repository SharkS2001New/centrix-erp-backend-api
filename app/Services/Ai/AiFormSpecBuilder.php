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
    public function forAction(User $user, array $action): ?array
    {
        $type = (string) ($action['type'] ?? '');
        $entity = $this->entityForAction($type);
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

        foreach ($schema['fields'] ?? [] as $name => $field) {
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
            'submit_label' => $type === 'record_customer_payment' ? 'Confirm & record payment' : 'Confirm & create',
        ];
    }

    protected function entityForAction(string $type): ?string
    {
        foreach (config('ai_entity_schemas', []) as $entity => $schema) {
            if (($schema['create_action'] ?? null) === $type) {
                return $entity;
            }
        }

        return match ($type) {
            'create_held_order' => 'sales_order',
            default => null,
        };
    }
}
