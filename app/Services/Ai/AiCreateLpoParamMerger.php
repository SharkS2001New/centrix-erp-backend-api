<?php

namespace App\Services\Ai;

use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Carbon\Carbon;

/**
 * Deterministically fold free-text LPO details into a pending create_lpo action.
 * Keeps multi-turn edits (due date, terms, lines, supplier) working without relying on the LLM.
 */
class AiCreateLpoParamMerger
{
    public function __construct(
        protected AiEntitySchemaCatalog $schemas,
    ) {}

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $entityRefs
     * @return array{pending: array<string, mixed>, changed: bool, notes: list<string>}
     */
    public function merge(User $user, array $pending, string $message, array $history = [], array $entityRefs = []): array
    {
        if ((string) ($pending['type'] ?? '') !== 'create_lpo') {
            return ['pending' => $pending, 'changed' => false, 'notes' => []];
        }

        $params = is_array($pending['params'] ?? null) ? $pending['params'] : [];
        $extracted = $this->extractFields($message);

        // Bare name reply: prefer supplier if still missing, otherwise treat as a product line.
        if (
            empty($extracted['supplier'])
            && empty($extracted['lines_raw'])
            && preg_match('/^[A-Za-z][\w\s&.\'-]{2,80}$/u', trim($message))
            && ! preg_match('/\b(confirm|save|yes|no|show\s*form|cancel)\b/i', $message)
        ) {
            if ((int) ($params['supplier_id'] ?? 0) <= 0) {
                $extracted['supplier'] = trim($message);
            } else {
                $extracted['lines_raw'] = trim($message).' x 1';
                $extracted['replace_lines'] = false;
            }
        }

        $notes = [];
        $changed = false;

        foreach (['due_date', 'delivery_address', 'reference_number', 'terms', 'instructions', 'order_num'] as $key) {
            if (! array_key_exists($key, $extracted)) {
                continue;
            }
            if (($params[$key] ?? null) !== $extracted[$key]) {
                $params[$key] = $extracted[$key];
                $changed = true;
                $notes[] = $this->noteForScalar($key, $extracted[$key]);
            }
        }

        if (! empty($extracted['supplier'])) {
            $resolved = $this->resolveSupplier($user, (string) $extracted['supplier']);
            if ($resolved === null) {
                $notes[] = 'Could not match supplier “'.$extracted['supplier'].'” — try the exact name, @mention the supplier, or reply **show form**.';
                $params['supplier_name'] = (string) $extracted['supplier'];
                $changed = true;
            } elseif ((int) ($params['supplier_id'] ?? 0) !== (int) $resolved['id']) {
                $params['supplier_id'] = (int) $resolved['id'];
                $params['supplier_name'] = $resolved['name'];
                $changed = true;
                $notes[] = 'Supplier: '.$resolved['name'];
            }
        } elseif ((int) ($params['supplier_id'] ?? 0) <= 0 && ! empty($params['supplier_name'])) {
            $resolved = $this->resolveSupplier($user, (string) $params['supplier_name']);
            if ($resolved !== null) {
                $params['supplier_id'] = (int) $resolved['id'];
                $params['supplier_name'] = $resolved['name'];
                $changed = true;
                $notes[] = 'Supplier: '.$resolved['name'];
            }
        } elseif ((int) ($params['supplier_id'] ?? 0) <= 0) {
            // Fall back to schema options / labeled supplier from history on first fill.
            $schema = $this->schemas->forEntityWithOptions($user, 'lpo');
            $options = is_array($schema['fields']['supplier_id']['options'] ?? null)
                ? $schema['fields']['supplier_id']['options']
                : [];
            if ($options !== [] && preg_match('/supplier\s*[:=]\s*([^\n]+)/i', $message, $m)) {
                $match = $this->matchOption(trim($m[1]), $options);
                if ($match !== null) {
                    $params['supplier_id'] = (int) $match['value'];
                    $params['supplier_name'] = $match['label'];
                    $changed = true;
                    $notes[] = 'Supplier: '.$match['label'];
                }
            }
        }

        // Resolve draft line rows that still only have product_name (from intent extract).
        if (is_array($params['lines'] ?? null) && $params['lines'] !== []) {
            $resolvedSeedLines = [];
            $seedChanged = false;
            foreach ($params['lines'] as $line) {
                if (! is_array($line)) {
                    continue;
                }
                if (! empty($line['product_code'])) {
                    $resolvedSeedLines[] = $line;
                    continue;
                }
                $name = trim((string) ($line['product_name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $product = $this->resolveProductByName($user, $name);
                if (! $product) {
                    $notes[] = 'Could not find product “'.$name.'” in your catalog.';
                    $seedChanged = true;
                    continue;
                }
                $resolvedSeedLines[] = [
                    'product_code' => $product->product_code,
                    'product_name' => $product->product_name,
                    'ordered_qty' => (float) ($line['ordered_qty'] ?? 1),
                    'cost_price' => array_key_exists('cost_price', $line) && $line['cost_price'] !== null && $line['cost_price'] !== ''
                        ? (float) $line['cost_price']
                        : (float) ($product->last_cost_price ?? 0),
                ];
                $seedChanged = true;
            }
            if ($seedChanged) {
                $params['lines'] = $resolvedSeedLines;
                $changed = true;
            }
        }

        $lineResult = $this->mergeLines($user, $params, $message, $extracted, $entityRefs);
        $params = $lineResult['params'];
        if ($lineResult['changed']) {
            $changed = true;
            $notes = array_merge($notes, $lineResult['notes']);
        }

        if ($changed) {
            $pending['params'] = $params;
            $supplierLabel = $params['supplier_name'] ?? ('#'.($params['supplier_id'] ?? ''));
            $lineCount = is_array($params['lines'] ?? null) ? count($params['lines']) : 0;
            $pending['summary'] = 'Create LPO: '.$supplierLabel.($lineCount > 0 ? " ({$lineCount} lines)" : '');
        }

        return ['pending' => $pending, 'changed' => $changed, 'notes' => $notes];
    }

    /** True when the message looks like filling / editing LPO draft fields. */
    public function looksLikeFieldFollowUp(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        if (preg_match(
            '/^(?:supplier|lines?|products?|items?|due\s*date|delivery|reference|terms|qty|quantity)\s*[:=]/i',
            $text,
        )) {
            return true;
        }

        if (preg_match(
            '/\b(change|update|set|make)\b.{0,20}\b(due\s*date|terms|delivery|reference|supplier|qty|quantity|lines?|products?)\b/i',
            $text,
        )) {
            return true;
        }

        if (preg_match('/\b(due\s*date|terms|delivery\s*address|reference\s*(?:no|number)?|line\s*items?)\b/i', $text)) {
            return true;
        }

        // Natural language: "for Bidco", "from Kamande supplies", "with 10 bags of sugar"
        if (preg_match('/\b(?:supplier|vendor|for|from|with)\b/i', $text)) {
            return true;
        }

        // "Cooking Oil 20L x 10" / "10 bags of Kamande" / multiline product lists
        if (preg_match('/\bx\s*\d+(?:\.\d+)?\b/i', $text) && preg_match('/[A-Za-z]{2,}/', $text)) {
            return true;
        }
        if (preg_match('/\d+(?:\.\d+)?\s*(?:bags?|units?|pcs?|pieces?|kg|kgs|litres?|l|cartons?)?\s*(?:of|x|×)\s+[A-Za-z]/i', $text)) {
            return true;
        }

        // @Product mentions with trailing qty
        if (str_contains($text, '@') && preg_match('/\d+(?:\.\d+)?\s*$/m', $text)) {
            return true;
        }

        // Bare supplier / product name reply during an LPO draft turn.
        if (preg_match('/^[A-Za-z][\w\s&.\'-]{2,80}$/u', $text)) {
            return true;
        }

        return (bool) preg_match('/^>\s*(supplier|lines?|due\s*date|terms|delivery|reference)\s*:/im', $text);
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  list<string>  $notes
     */
    public function statusReply(array $pending, array $notes = [], bool $voiceBrief = false): string
    {
        $params = is_array($pending['params'] ?? null) ? $pending['params'] : [];
        $productLines = is_array($params['lines'] ?? null) ? $params['lines'] : [];

        $supplier = trim((string) ($params['supplier_name'] ?? ''));
        if ($supplier === '' && (int) ($params['supplier_id'] ?? 0) > 0) {
            $supplier = 'Supplier #'.(int) $params['supplier_id'];
        }

        $missing = [];
        if ((int) ($params['supplier_id'] ?? 0) <= 0) {
            $missing[] = 'supplier';
        }
        if ($productLines === [] && trim((string) ($params['order_num'] ?? '')) === '' && (int) ($params['sale_id'] ?? 0) <= 0) {
            $missing[] = 'line items';
        }

        if ($voiceBrief) {
            foreach ($notes as $note) {
                if (str_starts_with($note, 'Could not match') || str_starts_with($note, 'Could not find')) {
                    return $note.' Which name should I use?';
                }
            }
            if ($missing === []) {
                $lineBits = [];
                foreach (array_slice($productLines, 0, 3) as $line) {
                    if (! is_array($line)) {
                        continue;
                    }
                    $lineBits[] = trim((string) ($line['product_name'] ?? $line['product_code'] ?? ''))
                        .' × '.($line['ordered_qty'] ?? '?');
                }
                $summary = ($supplier !== '' ? $supplier : 'Supplier')
                    .($lineBits !== [] ? ' - '.implode(', ', $lineBits) : '');

                return 'LPO draft ready: '.$summary.'. Say confirm to create it, or add more products.';
            }
            if (in_array('supplier', $missing, true)) {
                return $supplier !== ''
                    ? 'I heard supplier “'.$supplier.'” but could not match it. Say the exact supplier name.'
                    : 'Which supplier is this LPO for?';
            }

            return 'Got supplier '.($supplier !== '' ? $supplier : '').'. Which products and quantities? For example: Kamande x 10.';
        }

        $lines = ['Updated draft LPO — here is what I have:'];
        $lines[] = '• Supplier: '.($supplier !== '' ? $supplier : '— still needed');

        if (! empty($params['due_date'])) {
            $lines[] = '• Due date: '.$params['due_date'];
        }
        if (! empty($params['terms'])) {
            $lines[] = '• Terms: '.$params['terms'];
        }
        if (! empty($params['delivery_address'])) {
            $lines[] = '• Delivery: '.$params['delivery_address'];
        }
        if (! empty($params['reference_number'])) {
            $lines[] = '• Reference: '.$params['reference_number'];
        }

        if ($productLines !== []) {
            $lines[] = '• Lines:';
            foreach ($productLines as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $code = trim((string) ($line['product_code'] ?? ''));
                $name = trim((string) ($line['product_name'] ?? $code));
                $qty = $line['ordered_qty'] ?? $line['quantity'] ?? '?';
                $cost = array_key_exists('cost_price', $line) && $line['cost_price'] !== null && $line['cost_price'] !== ''
                    ? ' @ '.$line['cost_price']
                    : ' (last cost)';
                $lines[] = '  – '.$name.($code !== '' && $code !== $name ? " [{$code}]" : '').' × '.$qty.$cost;
            }
        } elseif (! empty($params['order_num']) || (int) ($params['sale_id'] ?? 0) > 0) {
            $lines[] = '• Lines: will copy from order '.($params['order_num'] ?? ('#'.$params['sale_id']));
        } else {
            $lines[] = '• Lines: — still needed';
        }

        foreach ($notes as $note) {
            if (str_starts_with($note, 'Could not match') || str_starts_with($note, 'Could not find')) {
                $lines[] = '• '.$note;
            }
        }

        $lines[] = '';
        if ($missing === []) {
            $lines[] = 'Ready to save. Reply **confirm** or **save** to create the LPO (PDF/print links will appear after). Or reply **show form** to review.';
        } else {
            $need = [];
            if (in_array('supplier', $missing, true)) {
                $need[] = 'supplier';
            }
            if (in_array('line items', $missing, true)) {
                $need[] = 'line items (or a sales order to copy from)';
            }
            $lines[] = 'Still need: '.implode(', ', $need).'.';
            $lines[] = 'You can reply in one sentence, e.g. “Supplier Bidco, Kamande x 10, due 30/09/2026”.';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function extractFields(string $message): array
    {
        $params = [];
        $text = trim($message);
        if ($text === '') {
            return [];
        }

        if (preg_match('/(?:^|>)\s*supplier\s*[:=]\s*([^\n]+)/im', $text, $m)
            || preg_match('/\bsupplier\s*[:=]\s*([^\n,;]+)/i', $text, $m)
            || preg_match('/\b(?:supplier|vendor)\s+(?:is\s+|named\s+)?([A-Za-z0-9][\w\s&.\'-]{1,60}?)(?=\s*(?:,|;|\.|$|\band\b|\bwith\b|\bdue\b|\bterms\b|\bfor\b|\bproducts?\b|\blines?\b|\d))/iu', $text, $m)
            || preg_match('/\b(?:lpo|purchase\s+order|po)\s+(?:for|from)\s+([A-Za-z0-9][\w\s&.\'-]{1,60}?)(?=\s*(?:,|;|\.|$|\band\b|\bwith\b|\bdue\b|\bterms\b|\bfor\b|\bproducts?\b|\blines?\b|\d))/iu', $text, $m)
            || preg_match('/\b(?:for|from)\s+(?:supplier\s+|vendor\s+)?([A-Za-z][\w\s&.\'-]{1,50}?)(?=\s*(?:,|;|\.|$|\band\b|\bwith\b|\bdue\b|\bterms\b|\bproducts?\b|\blines?\b|\bfor\b|\d+\s*(?:bags?|units?|x|×|of)))/iu', $text, $m)) {
            $candidate = $this->cleanEntityName($m[1]);
            if ($candidate !== null && ! $this->isMetaEntityName($candidate)) {
                $params['supplier'] = $candidate;
            }
        }

        if (preg_match('/(?:change|update|set|make)?\s*due\s*date\s*(?:to|=|:)?\s*([^\n,;]+)/i', $text, $m)
            || preg_match('/\bdue(?:\s*date)?\s*(?:on|by|to|=|:)?\s*([^\n,;]+?)(?=\s*(?:,|;|\.|$|\bterms\b|\band\b|\bwith\b)|\s*$)/i', $text, $m)) {
            $parsed = $this->parseDate(trim($m[1]));
            if ($parsed !== null) {
                $params['due_date'] = $parsed;
            }
        }

        if (preg_match('/(?:^|>)\s*terms\s*[:=]\s*([^\n]+)/im', $text, $m)
            || preg_match('/\bterms\s*[:=]\s*([^\n]+)/i', $text, $m)
            || preg_match('/\bterms\s+(\d+\s*days?(?:\s+credit)?|net\s*\d+|cod|cash)\b/i', $text, $m)) {
            $params['terms'] = trim($m[1], " \t\"'>");
        }

        if (preg_match('/(?:delivery(?:\s*address)?)\s*[:=]\s*([^\n]+)/i', $text, $m)) {
            $params['delivery_address'] = trim($m[1], " \t\"'>");
        }

        if (preg_match('/(?:reference(?:\s*(?:no|number|#))?)\s*[:=]\s*([^\n]+)/i', $text, $m)) {
            $params['reference_number'] = trim($m[1], " \t\"'>");
        }

        if (preg_match('/(?:^|>)\s*lines?\s*[:=]\s*([^\n]+)/im', $text, $m)) {
            $params['lines_raw'] = trim($m[1]);
            $params['replace_lines'] = true;
        } elseif (preg_match('/(?:^|>)\s*products?\s*[:=]\s*([\s\S]+)/im', $text, $m)) {
            $params['lines_raw'] = trim($m[1]);
            $params['replace_lines'] = true;
        } else {
            $naturalLines = $this->extractNaturalProductQtyPhrases($text);
            if ($naturalLines !== []) {
                $params['lines_raw'] = implode(', ', array_map(
                    fn ($line) => ($line['product_name'] ?? '').' x '.($line['ordered_qty'] ?? 1),
                    $naturalLines,
                ));
                $params['replace_lines'] = (bool) preg_match('/\b(lines?|products?|items?)\b/i', $text)
                    || preg_match('/\b(create|draft|make|raise)\b.{0,40}\b(lpo|purchase\s+order)\b/i', $text);
            }
        }

        if (preg_match('/\b(?:order|sale)\s*[#:]?\s*([A-Z0-9][\w\-\/]{2,40})\b/i', $text, $m)) {
            $orderNum = trim($m[1]);
            if ($this->looksLikeOrderNumber($orderNum)) {
                $params['order_num'] = $orderNum;
            }
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $extracted
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $entityRefs
     * @return array{params: array<string, mixed>, changed: bool, notes: list<string>}
     */
    protected function mergeLines(
        User $user,
        array $params,
        string $message,
        array $extracted,
        array $entityRefs,
    ): array {
        $notes = [];
        $changed = false;
        $existing = is_array($params['lines'] ?? null) ? $params['lines'] : [];
        $replace = ! empty($extracted['replace_lines']);

        $parsedLines = [];
        if (! empty($extracted['lines_raw'])) {
            $parsedLines = $this->parseLineList((string) $extracted['lines_raw']);
        } else {
            $parsedLines = $this->parseLineList($message);
            // Only treat free-text x-qty lines as a full replace when the message is clearly a product list.
            if ($parsedLines !== [] && preg_match('/\b(lines?|products?|items?)\b/i', $message)) {
                $replace = true;
            } elseif ($parsedLines !== [] && $existing === []) {
                $replace = true;
            } elseif ($parsedLines !== []) {
                // Additive updates for "add Sugar x 5" style without wiping prior lines.
                $replace = false;
            }
        }

        $fromMentions = $this->linesFromEntityRefs($message, $entityRefs);
        if ($fromMentions !== []) {
            if (preg_match('/\b(products?|lines?|items?)\s*[:=]/i', $message) || $existing === []) {
                $replace = $replace || $existing === [] || (bool) preg_match('/\b(products?|lines?)\s*[:=]/i', $message);
            }
            $parsedLines = array_merge($parsedLines, $fromMentions);
        }

        if ($parsedLines === []) {
            return ['params' => $params, 'changed' => false, 'notes' => []];
        }

        $resolved = [];
        foreach ($parsedLines as $line) {
            $code = trim((string) ($line['product_code'] ?? ''));
            $name = trim((string) ($line['product_name'] ?? ''));
            $qty = (float) ($line['ordered_qty'] ?? 0);
            if ($qty <= 0) {
                $qty = 1;
            }

            $product = null;
            if ($code !== '') {
                $product = Product::query()
                    ->where('organization_id', $user->organization_id)
                    ->whereNull('deleted_at')
                    ->where('product_code', $code)
                    ->first(['product_code', 'product_name', 'last_cost_price']);
            }
            if (! $product && $name !== '') {
                $product = $this->resolveProductByName($user, $name);
            }
            if (! $product) {
                $notes[] = 'Could not find product “'.($name !== '' ? $name : $code).'” in your catalog.';

                continue;
            }

            $entry = [
                'product_code' => $product->product_code,
                'product_name' => $product->product_name,
                'ordered_qty' => $qty,
                'cost_price' => array_key_exists('cost_price', $line) && $line['cost_price'] !== null && $line['cost_price'] !== ''
                    ? (float) $line['cost_price']
                    : (float) ($product->last_cost_price ?? 0),
            ];
            $resolved[strtoupper($product->product_code)] = $entry;
        }

        if ($resolved === []) {
            return ['params' => $params, 'changed' => true, 'notes' => $notes];
        }

        if ($replace) {
            $params['lines'] = array_values($resolved);
            $changed = true;
            $notes[] = 'Lines updated ('.count($resolved).')';
        } else {
            $byCode = [];
            foreach ($existing as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $code = strtoupper(trim((string) ($line['product_code'] ?? '')));
                if ($code !== '') {
                    $byCode[$code] = $line;
                }
            }
            foreach ($resolved as $code => $line) {
                $byCode[$code] = $line;
            }
            $merged = array_values($byCode);
            if ($merged !== $existing) {
                $params['lines'] = $merged;
                $changed = true;
                $notes[] = 'Lines updated ('.count($merged).')';
            }
        }

        return ['params' => $params, 'changed' => $changed, 'notes' => $notes];
    }

    /**
     * @return list<array{product_name?: string, product_code?: string, ordered_qty: float}>
     */
    public function parseLineList(string $text): array
    {
        $lines = [];
        $chunk = trim($text);
        if ($chunk === '') {
            return [];
        }

        // Prefer whole-sentence natural phrases first ("10 bags of Kamande and Sugar x 5").
        $natural = $this->extractNaturalProductQtyPhrases($chunk);
        if ($natural !== []) {
            return $natural;
        }

        // Split on commas / newlines / "and"
        $parts = preg_split('/\n+|,(?![^(]*\))|\band\b/i', $chunk) ?: [];
        foreach ($parts as $part) {
            $part = trim($part);
            $part = preg_replace('/^[-*>\s]+/', '', $part) ?? $part;
            $part = preg_replace('/^@/', '', $part) ?? $part;
            if ($part === '' || preg_match('/^(supplier|due\s*date|terms|delivery|reference)\b/i', $part)) {
                continue;
            }

            $name = null;
            $qty = 0.0;
            if (preg_match('/^(.+?)\s*[x×]\s*(\d+(?:\.\d+)?)\s*$/iu', $part, $m)) {
                $name = trim($m[1], " \t\"'");
                $qty = (float) $m[2];
            } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*(?:bags?|units?|pcs?|pieces?|kg|kgs|litres?|l|cartons?|crates?)?\s*(?:of|x|×)\s+(.+)$/iu', $part, $m)) {
                $qty = (float) $m[1];
                $name = trim($m[2], " \t\"'");
            } elseif (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)\s*(?:bags?|units?|pcs?|pieces?|kg|kgs)?\s*$/u', $part, $m)) {
                $name = trim($m[1], " \t\"'");
                $qty = (float) $m[2];
            }
            $name = $name !== null ? ($this->cleanEntityName($name) ?? $name) : '';
            if ($name !== '' && $qty > 0 && ! preg_match('/^\d+$/', $name)) {
                $lines[] = [
                    'product_name' => $name,
                    'ordered_qty' => $qty,
                ];
            }
        }

        return $lines;
    }

    /**
     * Pull "10 bags of Kamande", "Kamande x 10" style phrases from a free sentence.
     *
     * @return list<array{product_name: string, ordered_qty: float}>
     */
    public function extractNaturalProductQtyPhrases(string $text): array
    {
        $out = [];
        $seen = [];

        $add = function (string $name, float $qty) use (&$out, &$seen): void {
            $name = $this->cleanEntityName($name) ?? '';
            if ($name === '' || $qty <= 0 || $this->isMetaEntityName($name)) {
                return;
            }
            $key = mb_strtolower($name).'|'.$qty;
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'product_name' => $name,
                'ordered_qty' => $qty,
            ];
        };

        // "10 bags of Kamande" / "20 units of sugar" / "10 x Cooking Oil"
        if (preg_match_all(
            '/(\d+(?:\.\d+)?)\s*(?:bags?|units?|pcs?|pieces?|kg|kgs|litres?|l|cartons?|crates?)?\s*(?:of|x|×)\s+([A-Za-z][\w&.\'-]{1,40}(?:\s+[A-Za-z][\w&.\'-]{0,40}){0,4}?)(?=\s*(?:,|\band\b|\bdue\b|\bterms\b|\bfor\b|\bfrom\b|\bwith\b|\.|$))/iu',
            $text,
            $matches,
            PREG_SET_ORDER,
        )) {
            foreach ($matches as $m) {
                $add($m[2], (float) $m[1]);
            }
        }

        // "Kamande x 10" — walk back from each ×/x qty so sentence prefixes are not swallowed.
        if (preg_match_all('/[x×]\s*(\d+(?:\.\d+)?)\b/iu', $text, $xMatches, PREG_OFFSET_CAPTURE)) {
            $stop = [
                'for', 'from', 'with', 'and', 'due', 'terms', 'lpo', 'po', 'supplier', 'vendor',
                'create', 'draft', 'make', 'raise', 'generate', 'issue', 'prepare', 'purchase',
                'order', 'products', 'product', 'items', 'item', 'lines', 'line', 'a', 'an', 'the',
                'me', 'my', 'our', 'please', 'help', 'need', 'want',
            ];
            foreach ($xMatches[0] as $i => $full) {
                $qty = (float) $xMatches[1][$i][0];
                $before = substr($text, 0, (int) $full[1]);
                if (! preg_match('/([A-Za-z][\w&.\'-]*(?:\s+[A-Za-z0-9][\w&.\'-]*){0,5})\s*$/u', $before, $bm)) {
                    continue;
                }
                $tokens = preg_split('/\s+/', trim($bm[1])) ?: [];
                $nameTokens = [];
                foreach (array_reverse($tokens) as $tok) {
                    $plain = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '', $tok) ?? $tok);
                    if ($plain === '' || in_array($plain, $stop, true)) {
                        break;
                    }
                    array_unshift($nameTokens, $tok);
                    if (count($nameTokens) >= 5) {
                        break;
                    }
                }
                if ($nameTokens === []) {
                    continue;
                }
                $add(implode(' ', $nameTokens), $qty);
            }
        }

        return $out;
    }

    protected function cleanEntityName(?string $raw): ?string
    {
        $name = trim((string) $raw, " \t\"'.,;:");
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;
        $name = preg_replace('/\b(please|thanks|today|tomorrow)\b/i', '', $name) ?? $name;
        $name = preg_replace('/\b(for|from|with|and)$/i', '', $name) ?? $name;
        $name = trim($name, " \t\"'.,;:");
        if ($name === '' || mb_strlen($name) < 2) {
            return null;
        }
        if (preg_match('/^(a|an|the|me|us|my|our|this|that)$/i', $name)) {
            return null;
        }

        return $name;
    }

    protected function isMetaEntityName(string $name): bool
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return true;
        }
        if (preg_match('/^(supplier|vendor|lpo|purchase|order|purchase\s+order|po|terms|due|due\s*date|products?|items?|lines?|units?|bags?|pcs?|pieces?)$/i', $n)) {
            return true;
        }
        if (preg_match('/\b(create|draft|make|raise|generate|issue|prepare|help|need|want)\b/i', $n)) {
            return true;
        }
        if (preg_match('/\b(lpo|purchase\s+order|supplier|vendor)\b/i', $n)) {
            return true;
        }
        if (preg_match('/^(units?|bags?|pcs?|pieces?|kg|kgs|litres?|cartons?|crates?)\s+of\b/i', $n)) {
            return true;
        }

        return false;
    }

    protected function looksLikeOrderNumber(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^(from|for|with|the|a|an|to|and|of|order|sale)$/i', $value)) {
            return false;
        }

        return (bool) preg_match('/\d/', $value) || (bool) preg_match('/^(ORD|SO|POS|INV|LPO)[-_]?\w+/i', $value);
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<array{product_code?: string, product_name?: string, ordered_qty: float}>
     */
    protected function linesFromEntityRefs(string $message, array $refs): array
    {
        $out = [];
        foreach ($refs as $ref) {
            if (($ref['type'] ?? '') !== 'product') {
                continue;
            }
            $code = trim((string) ($ref['code'] ?? ''));
            $label = trim((string) ($ref['label'] ?? ''));
            $qty = $this->qtyNearLabel($message, $label !== '' ? $label : $code);
            $out[] = [
                'product_code' => $code !== '' ? $code : null,
                'product_name' => $label !== '' ? $label : null,
                'ordered_qty' => $qty,
            ];
        }

        return $out;
    }

    protected function qtyNearLabel(string $message, string $label): float
    {
        $label = trim($label);
        if ($label === '') {
            return 1.0;
        }
        $quoted = preg_quote($label, '/');
        if (preg_match('/@?'.$quoted.'\s*[x×]?\s*(\d+(?:\.\d+)?)/iu', $message, $m)) {
            return (float) $m[1];
        }
        // Trailing number on same line as label
        if (preg_match('/^.*'.preg_quote($label, '/').'.*?(\d+(?:\.\d+)?)\s*$/imu', $message, $m)) {
            return (float) $m[1];
        }

        return 1.0;
    }

    /** @return array{id: int, name: string}|null */
    public function resolveSupplier(User $user, string $name): ?array
    {
        $needle = trim($name);
        if ($needle === '') {
            return null;
        }

        $exact = Supplier::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(supplier_name) = ?', [mb_strtolower($needle)])
            ->first(['id', 'supplier_name']);
        if ($exact) {
            return ['id' => (int) $exact->id, 'name' => (string) $exact->supplier_name];
        }

        $like = Supplier::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle) {
                $q->where('supplier_name', 'like', '%'.$needle.'%')
                    ->orWhere('supplier_code', 'like', '%'.$needle.'%');
            })
            ->orderBy('supplier_name')
            ->limit(8)
            ->get(['id', 'supplier_name']);

        if ($like->count() === 1) {
            $row = $like->first();

            return ['id' => (int) $row->id, 'name' => (string) $row->supplier_name];
        }

        $scorer = app(AiSpokenEntityResolver::class);
        $pool = $like;
        if ($pool->isEmpty()) {
            $pool = Supplier::query()
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->orderBy('supplier_name')
                ->limit(250)
                ->get(['id', 'supplier_name']);
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($pool as $row) {
            $score = $scorer->scoreSpoken($needle, (string) $row->supplier_name);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        if ($best && $bestScore >= 72) {
            return ['id' => (int) $best->id, 'name' => (string) $best->supplier_name];
        }

        return null;
    }

    public function resolveProductByName(User $user, string $name): ?Product
    {
        $needle = trim($name);
        if (strlen($needle) < 2) {
            return null;
        }

        $exact = Product::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(product_name) = ?', [mb_strtolower($needle)])
                    ->orWhereRaw('LOWER(product_code) = ?', [mb_strtolower($needle)]);
            })
            ->first(['product_code', 'product_name', 'last_cost_price']);
        if ($exact) {
            return $exact;
        }

        $matches = Product::query()
            ->where('organization_id', $user->organization_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($needle) {
                $q->where('product_name', 'like', '%'.$needle.'%')
                    ->orWhere('product_code', 'like', '%'.$needle.'%');
            })
            ->orderBy('product_name')
            ->limit(12)
            ->get(['product_code', 'product_name', 'last_cost_price']);

        if ($matches->count() === 1) {
            return $matches->first();
        }

        $scorer = app(AiSpokenEntityResolver::class);
        $pool = $matches;
        if ($pool->isEmpty()) {
            $pool = Product::query()
                ->where('organization_id', $user->organization_id)
                ->whereNull('deleted_at')
                ->orderBy('product_name')
                ->limit(400)
                ->get(['product_code', 'product_name', 'last_cost_price']);
        }

        $best = null;
        $bestScore = 0.0;
        foreach ($pool as $row) {
            $score = max(
                $scorer->scoreSpoken($needle, (string) $row->product_name),
                $scorer->scoreSpoken($needle, (string) $row->product_code),
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return ($best && $bestScore >= 70) ? $best : null;
    }

    public function parseDate(string $raw): ?string
    {
        $raw = trim($raw, " \t\"'.");
        if ($raw === '') {
            return null;
        }

        // DD/MM/YYYY or DD-MM-YYYY
        if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})$/', $raw, $m)) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += 2000;
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{value?: mixed, label?: string}>  $options
     * @return array{value: mixed, label: string}|null
     */
    protected function matchOption(string $needle, array $options): ?array
    {
        $needleNorm = mb_strtolower(trim($needle));
        $best = null;
        $bestScore = 0;
        foreach ($options as $option) {
            $label = (string) ($option['label'] ?? '');
            $labelNorm = mb_strtolower(trim($label));
            if ($labelNorm === '') {
                continue;
            }
            $score = $labelNorm === $needleNorm ? 100 : (str_contains($labelNorm, $needleNorm) || str_contains($needleNorm, $labelNorm) ? 80 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = ['value' => $option['value'], 'label' => $label];
            }
        }

        return $bestScore >= 80 ? $best : null;
    }

    protected function noteForScalar(string $key, mixed $value): string
    {
        return match ($key) {
            'due_date' => 'Due date: '.$value,
            'terms' => 'Terms: '.$value,
            'delivery_address' => 'Delivery: '.$value,
            'reference_number' => 'Reference: '.$value,
            default => $key.': '.$value,
        };
    }
}
