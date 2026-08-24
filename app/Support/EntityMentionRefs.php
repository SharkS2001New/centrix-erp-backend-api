<?php

namespace App\Support;

/**
 * Normalize @-mention entity refs from AI chat / report builder.
 */
class EntityMentionRefs
{
    public const TYPES = ['product', 'supplier', 'customer', 'employee', 'user', 'branch'];

    /**
     * @param  mixed  $refs
     * @return list<array{type: string, id: ?string, code: ?string, label: string}>
     */
    public static function normalize(mixed $refs): array
    {
        if (! is_array($refs)) {
            return [];
        }

        $out = [];
        foreach (array_slice($refs, 0, 40) as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $type = strtolower(trim((string) ($ref['type'] ?? '')));
            if (! in_array($type, self::TYPES, true)) {
                continue;
            }
            $label = trim((string) ($ref['label'] ?? ''));
            $id = trim((string) ($ref['id'] ?? ''));
            $code = trim((string) ($ref['code'] ?? ''));
            if ($label === '' && $code === '' && $id === '') {
                continue;
            }
            if ($label === '') {
                $label = $code !== '' ? $code : $id;
            }
            $out[] = [
                'type' => $type,
                'id' => $id !== '' ? $id : null,
                'code' => $code !== '' ? $code : null,
                'label' => mb_substr($label, 0, 200),
            ];
        }

        return $out;
    }

    /**
     * Compact lines for LLM system context.
     *
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<string>
     */
    public static function contextLines(array $refs): array
    {
        $lines = [];
        foreach ($refs as $ref) {
            $bits = ["type={$ref['type']}", "label={$ref['label']}"];
            if (! empty($ref['code'])) {
                $bits[] = "code={$ref['code']}";
            }
            if (! empty($ref['id'])) {
                $bits[] = "id={$ref['id']}";
            }
            $lines[] = implode('; ', $bits);
        }

        return $lines;
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<string>
     */
    public static function productCodes(array $refs): array
    {
        $codes = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') !== 'product') {
                continue;
            }
            $code = trim((string) ($ref['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<string>
     */
    public static function customerNums(array $refs): array
    {
        $nums = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') !== 'customer') {
                continue;
            }
            $code = trim((string) ($ref['code'] ?? $ref['id'] ?? ''));
            if ($code !== '') {
                $nums[] = $code;
            }
        }

        return array_values(array_unique($nums));
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<int>
     */
    public static function supplierIds(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') !== 'supplier') {
                continue;
            }
            $id = (int) ($ref['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<int>
     */
    public static function branchIds(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') !== 'branch') {
                continue;
            }
            $id = (int) ($ref['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     */
    public static function hasType(array $refs, string $type): bool
    {
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }
}
