<?php

namespace App\Services\Ai;

/**
 * Resolve the active customer/supplier (etc.) from chat history so pronoun
 * follow-ups ("she", "they", "this customer") keep the same party in focus.
 */
class AiConversationFocusResolver
{
    /**
     * @param  list<array{role?: string, content?: string}>  $history
     * @param  list<array{type?: string, id?: ?string, code?: ?string, label?: string}>  $entityRefs
     * @param  array<string, mixed>|null  $pageContext
     * @return array{
     *   customers: list<array{customer_num: string, customer_name: ?string}>,
     *   suppliers: list<array{supplier_id: string, supplier_name: ?string}>
     * }
     */
    public function resolve(array $history, array $entityRefs = [], ?array $pageContext = null): array
    {
        $customers = [];
        $suppliers = [];

        foreach ($entityRefs as $ref) {
            if (! is_array($ref)) {
                continue;
            }
            $type = strtolower(trim((string) ($ref['type'] ?? '')));
            $label = trim((string) ($ref['label'] ?? ''));
            $code = trim((string) ($ref['code'] ?? $ref['id'] ?? ''));
            if ($type === 'customer' && $code !== '') {
                $customers[$code] = [
                    'customer_num' => $code,
                    'customer_name' => $label !== '' ? $label : null,
                ];
            }
            if ($type === 'supplier' && $code !== '') {
                $suppliers[$code] = [
                    'supplier_id' => $code,
                    'supplier_name' => $label !== '' ? $label : null,
                ];
            }
        }

        if (is_array($pageContext)) {
            $entity = strtolower(trim((string) ($pageContext['entity'] ?? '')));
            $entityId = trim((string) ($pageContext['entity_id'] ?? ''));
            $title = trim((string) ($pageContext['title'] ?? ''));
            if ($entity === 'customer' && $entityId !== '') {
                $customers[$entityId] = [
                    'customer_num' => $entityId,
                    'customer_name' => $title !== '' ? $title : ($customers[$entityId]['customer_name'] ?? null),
                ];
            }
            if ($entity === 'supplier' && $entityId !== '') {
                $suppliers[$entityId] = [
                    'supplier_id' => $entityId,
                    'supplier_name' => $title !== '' ? $title : ($suppliers[$entityId]['supplier_name'] ?? null),
                ];
            }
        }

        // Newest turns win — walk history newest-first.
        foreach (array_reverse($history) as $turn) {
            $content = (string) ($turn['content'] ?? '');
            if ($content === '') {
                continue;
            }
            foreach ($this->customersFromText($content) as $row) {
                $num = $row['customer_num'];
                if (! isset($customers[$num])) {
                    $customers[$num] = $row;
                } elseif (($customers[$num]['customer_name'] ?? null) === null && ($row['customer_name'] ?? null) !== null) {
                    $customers[$num]['customer_name'] = $row['customer_name'];
                }
            }
            foreach ($this->suppliersFromText($content) as $row) {
                $id = $row['supplier_id'];
                if (! isset($suppliers[$id])) {
                    $suppliers[$id] = $row;
                } elseif (($suppliers[$id]['supplier_name'] ?? null) === null && ($row['supplier_name'] ?? null) !== null) {
                    $suppliers[$id]['supplier_name'] = $row['supplier_name'];
                }
            }
        }

        return [
            'customers' => array_values($customers),
            'suppliers' => array_values($suppliers),
        ];
    }

    /**
     * When the user uses pronouns / "this customer", attach the focused party as an
     * entity ref so tools receive an exact customer_num / supplier id.
     *
     * @param  list<array{role?: string, content?: string}>  $history
     * @param  list<array{type?: string, id?: ?string, code?: ?string, label?: string}>  $entityRefs
     * @param  array<string, mixed>|null  $pageContext
     * @return list<array{type: string, id: ?string, code: ?string, label: string}>
     */
    public function enrichEntityRefs(
        string $message,
        array $history,
        array $entityRefs = [],
        ?array $pageContext = null,
    ): array {
        $focus = $this->resolve($history, $entityRefs, $pageContext);
        $out = array_values($entityRefs);

        if ($this->messageUsesCustomerPronoun($message) && ! $this->hasEntityType($out, 'customer')) {
            $customer = $focus['customers'][0] ?? null;
            if ($customer !== null) {
                $out[] = [
                    'type' => 'customer',
                    'id' => (string) $customer['customer_num'],
                    'code' => (string) $customer['customer_num'],
                    'label' => (string) ($customer['customer_name'] ?? $customer['customer_num']),
                ];
            }
        }

        if ($this->messageUsesSupplierPronoun($message) && ! $this->hasEntityType($out, 'supplier')) {
            $supplier = $focus['suppliers'][0] ?? null;
            if ($supplier !== null) {
                $out[] = [
                    'type' => 'supplier',
                    'id' => (string) $supplier['supplier_id'],
                    'code' => (string) $supplier['supplier_id'],
                    'label' => (string) ($supplier['supplier_name'] ?? $supplier['supplier_id']),
                ];
            }
        }

        return $out;
    }

    /**
     * @param  array{
     *   customers: list<array{customer_num: string, customer_name: ?string}>,
     *   suppliers: list<array{supplier_id: string, supplier_name: ?string}>
     * }  $focus
     */
    public function promptBlock(array $focus): string
    {
        $lines = [];
        foreach (array_slice($focus['customers'], 0, 3) as $c) {
            $name = trim((string) ($c['customer_name'] ?? ''));
            $num = (string) $c['customer_num'];
            $lines[] = $name !== ''
                ? "- Customer in focus: {$name} (customer_num={$num}) — for he/she/they/them/this customer, pass customer_num={$num} to get_customer_statement (not run_insight)."
                : "- Customer in focus: customer_num={$num} — for he/she/they/them/this customer, pass customer_num={$num} to get_customer_statement (not run_insight).";
        }
        foreach (array_slice($focus['suppliers'], 0, 3) as $s) {
            $name = trim((string) ($s['supplier_name'] ?? ''));
            $id = (string) $s['supplier_id'];
            $lines[] = $name !== ''
                ? "- Supplier in focus: {$name} (supplier_id={$id}) — for they/them/this supplier, pass supplier_id={$id} to get_supplier_statement."
                : "- Supplier in focus: supplier_id={$id} — for they/them/this supplier, pass supplier_id={$id} to get_supplier_statement.";
        }

        if ($lines === []) {
            return '';
        }

        return "Conversation focus (resolve pronouns from here — do not ask which party if focus is set):\n"
            .implode("\n", $lines)."\n\n";
    }

    public function messageUsesCustomerPronoun(string $message): bool
    {
        return (bool) preg_match(
            '/\b(he|she|him|her|his|hers|they|them|their|theirs|this\s+customer|that\s+customer|the\s+customer)\b/i',
            $message,
        );
    }

    public function messageUsesSupplierPronoun(string $message): bool
    {
        return (bool) preg_match(
            '/\b(this\s+supplier|that\s+supplier|the\s+supplier)\b/i',
            $message,
        ) || (
            (bool) preg_match('/\b(they|them|their|theirs)\b/i', $message)
            && (bool) preg_match('/\b(supplier|bought\s+from|owe|payable)\b/i', $message)
        );
    }

    /**
     * @param  list<array{type?: string}>  $refs
     */
    protected function hasEntityType(array $refs, string $type): bool
    {
        foreach ($refs as $ref) {
            if (strtolower(trim((string) ($ref['type'] ?? ''))) === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{customer_num: string, customer_name: ?string}>
     */
    protected function customersFromText(string $content): array
    {
        $out = [];
        if (! preg_match_all('#/customers/(\d+)#i', $content, $matches)) {
            return $out;
        }

        $name = $this->guessDisplayName($content);

        foreach ($matches[1] as $num) {
            $out[] = [
                'customer_num' => (string) $num,
                'customer_name' => $name,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{supplier_id: string, supplier_name: ?string}>
     */
    protected function suppliersFromText(string $content): array
    {
        $out = [];
        if (! preg_match_all('#/suppliers/(\d+)#i', $content, $matches)) {
            return $out;
        }

        $name = $this->guessDisplayName($content);

        foreach ($matches[1] as $id) {
            $out[] = [
                'supplier_id' => (string) $id,
                'supplier_name' => $name,
            ];
        }

        return $out;
    }

    /**
     * Prefer bold markdown names (common in assistant replies), else null.
     */
    protected function guessDisplayName(string $content): ?string
    {
        if (! preg_match_all('/\*\*([^*]{2,80})\*\*/u', $content, $matches)) {
            return null;
        }

        foreach ($matches[1] as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }
            if (preg_match('/^KES\b/i', $name) || preg_match('/^\d/', $name)) {
                continue;
            }
            if (preg_match('/^\d[\d,.\s]*$/', $name)) {
                continue;
            }

            return $name;
        }

        return null;
    }
}
