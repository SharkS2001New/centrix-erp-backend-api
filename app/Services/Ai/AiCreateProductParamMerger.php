<?php

namespace App\Services\Ai;

use App\Models\User;

/**
 * Deterministically fold free-text product details into a pending create_product action.
 * Keeps short follow-ups (e.g. "VAT 16%", "Uom: bags") working when the LLM returns empty.
 */
class AiCreateProductParamMerger
{
    public function __construct(
        protected AiEntitySchemaCatalog $schemas,
    ) {}

    /**
     * @param  array<string, mixed>  $pending
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array{pending: array<string, mixed>, changed: bool, notes: list<string>}
     */
    public function merge(User $user, array $pending, string $message, array $history = []): array
    {
        if ((string) ($pending['type'] ?? '') !== 'create_product') {
            return ['pending' => $pending, 'changed' => false, 'notes' => []];
        }

        $params = is_array($pending['params'] ?? null) ? $pending['params'] : [];
        $extracted = $this->extractFields($message, $history);
        $notes = [];
        $changed = false;

        foreach (['product_name', 'product_code', 'unit_price', 'last_cost_price', 'product_weight', 'reorder_point'] as $key) {
            if (! array_key_exists($key, $extracted)) {
                continue;
            }
            if ($this->paramFilled($params, $key) && ! $this->messageMentionsField($message, $key)) {
                continue;
            }
            if (($params[$key] ?? null) !== $extracted[$key]) {
                $params[$key] = $extracted[$key];
                $changed = true;
                $notes[] = $this->noteForScalar($key, $extracted[$key]);
            }
        }

        $schema = $this->schemas->forEntityWithOptions($user, 'product');
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];

        foreach (['vat_id' => 'vat', 'unit_id' => 'unit', 'subcategory_id' => 'subcategory', 'supplier_id' => 'supplier'] as $idKey => $extractKey) {
            $label = $extracted[$extractKey] ?? null;
            if (! is_string($label) || trim($label) === '') {
                continue;
            }
            if ($this->paramFilled($params, $idKey) && ! $this->messageMentionsField($message, $idKey)) {
                continue;
            }
            $options = is_array($fields[$idKey]['options'] ?? null) ? $fields[$idKey]['options'] : [];
            $match = $this->matchOption($label, $options);
            if ($match === null) {
                $notes[] = 'Could not match '.str_replace('_', ' ', $extractKey).' “'.$label.'” to a catalog value — try the exact name from your list, or reply **show form**.';
                continue;
            }
            if ((int) ($params[$idKey] ?? 0) !== (int) $match['value']) {
                $params[$idKey] = (int) $match['value'];
                $changed = true;
                $notes[] = ($fields[$idKey]['label'] ?? $idKey).': '.$match['label'];
            }
        }

        if ($changed) {
            $pending['params'] = $params;
            if (! empty($params['product_name'])) {
                $pending['summary'] = 'Create product: '.$params['product_name'];
            }
        }

        return ['pending' => $pending, 'changed' => $changed, 'notes' => $notes];
    }

    /** True when the message looks like filling create-product fields (not a new question). */
    public function looksLikeFieldFollowUp(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return false;
        }

        if (preg_match(
            '/^(?:vat(?:\s*rate)?|uom|unit(?:\s*of\s*measure)?|sub-?categor(?:y|ies)?|categor(?:y|ies)?|selling\s*price|cost\s*price|price|product\s*name|name|barcode|sku|weight|reorder(?:\s*point)?)\s*[:=]/i',
            $text,
        )) {
            return true;
        }

        return (bool) preg_match(
            '/\b(vat(?:\s*rate)?|vatable|vat\s*exempt|standard\s*rated|uom|unit of measure|sub-?categor|bags?|cartons?|pcs|pieces?|selling\s*price|cost\s*price|reorder\s*point|product\s*weight)\b/i',
            $text,
        );
    }

    /**
     * @param  array<string, mixed>  $pending
     * @param  list<string>  $notes
     */
    public function statusReply(array $pending, array $notes = []): string
    {
        $params = is_array($pending['params'] ?? null) ? $pending['params'] : [];
        $lines = ['Got it — here is what I have so far:'];

        if (! empty($params['product_name'])) {
            $lines[] = '• Product name: '.$params['product_name'];
        }
        if ($this->paramFilled($params, 'unit_price')) {
            $lines[] = '• Selling price: KES '.$params['unit_price'];
        }
        if ($this->paramFilled($params, 'last_cost_price')) {
            $lines[] = '• Cost price: KES '.$params['last_cost_price'];
        }
        if ($this->paramFilled($params, 'subcategory_id')) {
            $lines[] = '• Sub-category: set';
        }
        if ($this->paramFilled($params, 'unit_id')) {
            $lines[] = '• Unit of measure: set';
        }
        if ($this->paramFilled($params, 'vat_id')) {
            $lines[] = '• VAT rate: set';
        }

        foreach ($notes as $note) {
            if (! str_starts_with($note, 'Could not match')) {
                continue;
            }
            $lines[] = '• '.$note;
        }

        $missing = [];
        if (trim((string) ($params['product_name'] ?? '')) === '') {
            $missing[] = 'product name';
        }
        if (! $this->paramFilled($params, 'subcategory_id')) {
            $missing[] = 'sub-category';
        }
        if (! $this->paramFilled($params, 'unit_id')) {
            $missing[] = 'unit of measure';
        }
        if (! $this->paramFilled($params, 'vat_id')) {
            $missing[] = 'VAT rate';
        }
        if (! $this->paramFilled($params, 'unit_price')) {
            $missing[] = 'selling price';
        }

        if ($missing === []) {
            $lines[] = '';
            $lines[] = 'Everything required is set. Reply **confirm** or **save** to create the product (or **show form** to review).';
        } else {
            $lines[] = '';
            $lines[] = 'Still need: '.implode(', ', $missing).'.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, array{role?: string, content?: string}>  $history
     * @return array<string, mixed>
     */
    public function extractFields(string $message, array $history = []): array
    {
        $params = [];
        $sources = [$message];
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? '') === 'user') {
                $sources[] = (string) ($turn['content'] ?? '');
            }
        }

        foreach ($sources as $source) {
            $chunk = $this->extractFromText($source);
            // Prefer the newest message values (sources are newest-first).
            foreach ($chunk as $key => $value) {
                if (! array_key_exists($key, $params)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }

    /** @return array<string, mixed> */
    protected function extractFromText(string $source): array
    {
        $params = [];
        $text = trim($source);
        if ($text === '') {
            return [];
        }

        if (preg_match('/(?:product\s*)?name\s*[:=]\s*["\']?([^"\'\n]+?)["\']?\s*$/im', $text, $m)
            || preg_match('/(?:named|called)\s+["\']([^"\']+)["\']/i', $text, $m)
            || preg_match('/(?:named|called)\s+([A-Za-z0-9][A-Za-z0-9 \-]{1,80})/i', $text, $m)) {
            $params['product_name'] = trim($m[1]);
        }

        if (preg_match('/(?:selling\s*)?price\s*[:=]?\s*(?:kes\s*)?(\d+(?:\.\d+)?)/i', $text, $m)
            || preg_match('/\b(?:price|at|for)\s+(?:kes\s*)?(\d+(?:\.\d+)?)/i', $text, $m)) {
            $params['unit_price'] = (float) $m[1];
        }

        if (preg_match('/(?:last\s*)?cost(?:\s*price)?\s*[:=]?\s*(?:kes\s*)?(\d+(?:\.\d+)?)/i', $text, $m)) {
            $params['last_cost_price'] = (float) $m[1];
        }

        if (preg_match('/(?:product\s*)?weight\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $text, $m)) {
            $params['product_weight'] = (float) $m[1];
        }

        if (preg_match('/reorder(?:\s*point)?\s*[:=]?\s*(\d+(?:\.\d+)?)/i', $text, $m)) {
            $params['reorder_point'] = (float) $m[1];
        }

        if (preg_match('/(?:barcode|sku|code)\s*[:=]\s*["\']?([A-Za-z0-9\-#]+)["\']?/i', $text, $m)) {
            $params['product_code'] = trim($m[1]);
        }

        if (preg_match('/(?:vat(?:\s*rate)?)\s*[:=]\s*([^\n,;]+)/i', $text, $m)
            || preg_match('/\b(vat\s*exempt|standard\s*rated|vatable|vat\s*\d+\s*%?)/i', $text, $m)) {
            $params['vat'] = trim($m[1]);
        }

        if (preg_match('/(?:uom|unit(?:\s*of\s*measure)?)\s*[:=]\s*([^\n,;]+)/i', $text, $m)) {
            $params['unit'] = trim($m[1]);
        }

        if (preg_match('/(?:sub-?\s*categor(?:y|ies)?|categor(?:y|ies)?)\s*[:=]\s*([^\n,;]+)/i', $text, $m)) {
            $params['subcategory'] = trim($m[1]);
        }

        if (preg_match('/supplier\s*[:=]\s*([^\n,;]+)/i', $text, $m)) {
            $params['supplier'] = trim($m[1]);
        }

        return $params;
    }

    /**
     * @param  list<array{value?: mixed, label?: string}>  $options
     * @return array{value: mixed, label: string}|null
     */
    public function matchOption(string $needle, array $options): ?array
    {
        $needleNorm = $this->normalizeLabel($needle);
        if ($needleNorm === '' || $options === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($options as $option) {
            $label = (string) ($option['label'] ?? '');
            $labelNorm = $this->normalizeLabel($label);
            if ($labelNorm === '') {
                continue;
            }

            $score = 0;
            if ($labelNorm === $needleNorm) {
                $score = 100;
            } elseif (str_contains($labelNorm, $needleNorm) || str_contains($needleNorm, $labelNorm)) {
                $score = 80;
            } else {
                // Token overlap (e.g. "vat 16" vs "VAT 16%")
                $needleTokens = array_filter(explode(' ', $needleNorm));
                $labelTokens = array_filter(explode(' ', $labelNorm));
                $overlap = count(array_intersect($needleTokens, $labelTokens));
                if ($overlap > 0) {
                    $score = (int) round(60 * ($overlap / max(count($needleTokens), 1)));
                }
            }

            // Prefer VAT 16% over vague "Vatable" when user said 16%
            if (preg_match('/\b(\d+)\b/', $needleNorm, $nm) && preg_match('/\b(\d+)\b/', $labelNorm, $lm)) {
                if ($nm[1] === $lm[1]) {
                    $score += 15;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'value' => $option['value'],
                    'label' => $label,
                ];
            }
        }

        return $bestScore >= 50 ? $best : null;
    }

    protected function normalizeLabel(string $value): string
    {
        $text = strtolower(trim($value));
        $text = preg_replace('/[()]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = str_replace(['%', '-'], ['', ' '], $text);

        return trim($text);
    }

    /** @param  array<string, mixed>  $params */
    protected function paramFilled(array $params, string $key): bool
    {
        if (! array_key_exists($key, $params) || $params[$key] === null || $params[$key] === '') {
            return false;
        }

        if (in_array($key, ['vat_id', 'unit_id', 'subcategory_id', 'supplier_id'], true)) {
            return (int) $params[$key] > 0;
        }

        return true;
    }

    protected function messageMentionsField(string $message, string $key): bool
    {
        return match ($key) {
            'product_name' => (bool) preg_match('/\b(product\s*name|named|called)\b/i', $message),
            'unit_price' => (bool) preg_match('/\b(selling\s*price|unit\s*price|\bprice\b)/i', $message),
            'last_cost_price' => (bool) preg_match('/\bcost\b/i', $message),
            'vat_id' => (bool) preg_match('/\bvat\b/i', $message),
            'unit_id' => (bool) preg_match('/\b(uom|unit)\b/i', $message),
            'subcategory_id' => (bool) preg_match('/\b(sub-?\s*categor|categor)/i', $message),
            'supplier_id' => (bool) preg_match('/\bsupplier\b/i', $message),
            default => true,
        };
    }

    protected function noteForScalar(string $key, mixed $value): string
    {
        return match ($key) {
            'product_name' => 'Product name: '.$value,
            'unit_price' => 'Selling price: KES '.$value,
            'last_cost_price' => 'Cost price: KES '.$value,
            default => $key.': '.$value,
        };
    }
}
