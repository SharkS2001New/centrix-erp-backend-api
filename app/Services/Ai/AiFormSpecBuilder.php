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
        $params = is_array($action['params'] ?? null) ? $action['params'] : [];

        if (in_array($type, AiActionExecutor::lpoWorkflowActionTypes(), true)) {
            return $this->lpoWorkflowForm($type, $params);
        }

        $entity = $this->entityForAction($type, $pathname);
        if (! $entity) {
            return null;
        }

        $schema = $this->schemas->forEntityWithOptions($user, $entity);
        if (! $schema) {
            return null;
        }

        $fields = [];
        $hints = [];
        $fieldNames = $schema['ai_form_fields'] ?? array_keys($schema['fields'] ?? []);

        foreach ($fieldNames as $name) {
            $field = $schema['fields'][$name] ?? null;
            if (! is_array($field)) {
                continue;
            }

            if (($field['type'] ?? '') === 'line_items') {
                $hints[] = 'Line items: tell me product codes, quantities, and costs in chat — or reply **show form** to use a form.';

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
                'create_lpo' => 'Confirm & create LPO',
                default => 'Confirm & create',
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    protected function lpoWorkflowForm(string $type, array $params): array
    {
        $title = match ($type) {
            'submit_lpo_for_approval' => 'Submit LPO for approval',
            'approve_lpo' => 'Approve LPO',
            'mark_lpo_sent' => 'Mark LPO as sent',
            'receive_lpo_goods' => 'Receive LPO goods',
            default => 'LPO action',
        };

        $fields = [
            [
                'name' => 'lpo_no',
                'label' => 'LPO number',
                'type' => 'number',
                'required' => true,
                'value' => $params['lpo_no'] ?? null,
            ],
        ];

        if ($type === 'receive_lpo_goods') {
            $fields[] = [
                'name' => 'receive_all',
                'label' => 'Receive all remaining quantities',
                'type' => 'boolean',
                'value' => array_key_exists('receive_all', $params) ? (bool) $params['receive_all'] : true,
            ];
            $fields[] = [
                'name' => 'invoice_number',
                'label' => 'Supplier invoice number (optional)',
                'type' => 'text',
                'required' => false,
                'value' => $params['invoice_number'] ?? null,
            ];
        }

        return [
            'entity' => 'lpo',
            'action_type' => $type,
            'title' => $title,
            'fields' => $fields,
            'hints' => ['Reply **confirm** in chat if the LPO number is already correct.'],
            'submit_label' => match ($type) {
                'submit_lpo_for_approval' => 'Confirm & submit',
                'approve_lpo' => 'Confirm & approve',
                'mark_lpo_sent' => 'Confirm & mark sent',
                'receive_lpo_goods' => 'Confirm & receive',
                default => 'Confirm',
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
