<?php

namespace App\Services\Reports;

use App\Exceptions\Ai\AiProviderException;
use App\Models\User;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Support\AppTimezone;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReportBuilderSuggestService
{
    /** Prefer these master sources when both exist (mirrors frontend REPORT_BUILDER_MASTER_FIELDS). */
    protected const MASTER_FIELDS = [
        'product_name' => 'products',
        'product_code' => 'products',
        'category_name' => 'products',
        'customer_name' => 'customers',
        'customer_num' => 'customers',
        'customer_phone' => 'customers',
        'branch_name' => 'branches',
        'branch_code' => 'branches',
        'supplier_name' => 'suppliers',
        'supplier_code' => 'suppliers',
        'supplier_town' => 'suppliers',
    ];

    public function __construct(
        protected ReportBuilderService $builder,
        protected AiProviderFactory $providers,
    ) {}

    /**
     * @param  list<string>|null  $selectedProductCodes
     * @return array<string, mixed>
     */
    public function suggest(User $user, string $instruction, ?string $workspaceId = null, ?array $selectedProductCodes = null): array
    {
        $instruction = trim(preg_replace('/\s+/u', ' ', $instruction) ?? '');
        if ($instruction === '') {
            throw ValidationException::withMessages([
                'instruction' => ['Describe the report you need.'],
            ]);
        }

        $wordCount = count(preg_split('/\s+/u', $instruction, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($wordCount > 100) {
            throw ValidationException::withMessages([
                'instruction' => ['Keep the description under 100 words.'],
            ]);
        }

        $schema = $this->builder->schema($workspaceId);

        $runtime = AiSettingsResolver::isAvailableForUser($user)
            ? AiSettingsResolver::resolveRuntime($user)
            : null;

        if ($runtime) {
            try {
                $draft = $this->askModel($runtime, $instruction, $this->compactSchema($schema));
                $normalized = $this->normalizeDraft($draft, $schema, $workspaceId);
                $normalized['mode'] = 'ai';
                $normalized['provider'] = (string) ($runtime['provider'] ?? 'openai');

                return $this->attachFiltersAndProducts($normalized, $instruction, $user, $draft, $selectedProductCodes);
            } catch (ValidationException $e) {
                Log::info('Report builder AI suggest fell back to local matching', [
                    'message' => $e->getMessage(),
                    'provider' => $runtime['provider'] ?? null,
                ]);
            }
        }

        $draft = $this->draftFromKeywords($instruction, $schema);
        $normalized = $this->normalizeDraft($draft, $schema, $workspaceId);
        $normalized['mode'] = 'local';
        $normalized['provider'] = null;

        return $this->attachFiltersAndProducts($normalized, $instruction, $user, $draft, $selectedProductCodes);
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array{sources: list<array<string, mixed>>, blend_dimensions: list<array<string, mixed>>, max_sources: int, max_columns: int|null}
     */
    protected function compactSchema(array $schema): array
    {
        $sources = [];
        foreach ($schema['sources'] ?? [] as $source) {
            $fields = [];
            foreach ($source['fields'] ?? [] as $field) {
                $fields[] = [
                    'key' => $field['key'],
                    'label' => $field['label'] ?? $field['key'],
                    'type' => $field['type'] ?? 'string',
                    'groupable' => (bool) ($field['groupable'] ?? false),
                    'aggregates' => array_values($field['aggregates'] ?? []),
                ];
            }
            $sources[] = [
                'key' => $source['key'],
                'label' => $source['label'] ?? $source['key'],
                'description' => $source['description'] ?? '',
                'module' => $source['module'] ?? 'General',
                'fields' => $fields,
            ];
        }

        $blend = [];
        foreach ($schema['blend_dimensions'] ?? [] as $dim) {
            $blend[] = [
                'key' => $dim['key'],
                'label' => $dim['label'] ?? $dim['key'],
                'sources' => array_values($dim['sources'] ?? []),
            ];
        }

        return [
            'sources' => $sources,
            'blend_dimensions' => $blend,
            'max_sources' => (int) ($schema['max_sources'] ?? 4),
            'max_columns' => $schema['max_columns'] ?? null,
        ];
    }

    /**
     * Keyword / schema matching when org AI is off or the model call fails.
     * Also used by the AI create_custom_report tool (no nested LLM during tool rounds).
     *
     * @return array{name: string, description: string|null, spec: array<string, mixed>}
     */
    public function localDraft(User $user, string $instruction, ?string $workspaceId = null): array
    {
        $instruction = trim(preg_replace('/\s+/u', ' ', $instruction) ?? '');
        if ($instruction === '') {
            throw ValidationException::withMessages([
                'instruction' => ['Describe the report you need.'],
            ]);
        }

        $schema = $this->builder->schema($workspaceId);
        $draft = $this->draftFromKeywords($instruction, $schema);

        return $this->normalizeDraft($draft, $schema, $workspaceId);
    }

    /**
     * Keyword / schema matching when org AI is off or the model call fails.
     *
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    protected function draftFromKeywords(string $instruction, array $schema): array
    {
        $text = mb_strtolower($instruction);
        $tokens = $this->tokenize($text);
        $tokenSet = array_fill_keys($tokens, true);

        $wantsSummary = $this->textHasAny($text, ['total', 'totals', 'sum', 'summary', 'by day', 'daily', 'monthly', 'weekly', 'grouped', 'per ']);
        $wantsUnpaid = $this->textHasAny($text, ['unpaid', 'owing', 'outstanding', 'balance due', 'not paid']);
        $wantsPaid = $this->textHasAny($text, ['paid', 'payments collected']) && ! $wantsUnpaid;
        $wantsProduct = $this->textHasAny($text, ['product', 'products', 'sku', 'item', 'items', 'stock', 'inventory']);
        $wantsCustomer = $this->textHasAny($text, ['customer', 'customers', 'debtor', 'client', 'buyer']);
        $wantsBranch = $this->textHasAny($text, ['branch', 'branches', 'store', 'outlet', 'location']);
        $wantsSupplier = $this->textHasAny($text, ['supplier', 'suppliers', 'vendor', 'purchase', 'lpo', 'procurement']);
        $wantsEmployee = $this->textHasAny($text, ['employee', 'employees', 'payroll', 'staff', 'hr']);
        $wantsAttendance = $this->textHasAny($text, ['attendance', 'clock', 'check in', 'check-in', 'absent', 'lateness', 'late']);
        $wantsDaily = $this->textHasAny($text, ['daily', 'by day', 'each day', 'per day', 'sale date', 'date']);

        $sourceScores = [];
        foreach ($schema['sources'] ?? [] as $source) {
            $key = (string) ($source['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $haystack = mb_strtolower(implode(' ', [
                $key,
                str_replace('_', ' ', $key),
                $source['label'] ?? '',
                $source['description'] ?? '',
                $source['module'] ?? '',
            ]));
            $score = $this->overlapScore($tokens, $haystack);
            $score += $this->sourceKeywordBoost($key, $tokenSet, $text);
            if ($wantsProduct && in_array($key, ['sale_items', 'products', 'stock_movements', 'inventory'], true)) {
                $score += 4;
            }
            if ($wantsCustomer && in_array($key, ['customers', 'sales', 'customer_invoices'], true)) {
                $score += 3;
            }
            if ($wantsBranch && in_array($key, ['branches', 'sales', 'sale_items'], true)) {
                $score += 2;
            }
            if ($wantsSupplier && (str_contains($key, 'supplier') || str_contains($key, 'lpo') || str_contains($key, 'purchase'))) {
                $score += 4;
            }
            if ($wantsEmployee && (str_contains($key, 'employee') || str_contains($key, 'payroll') || ($source['module'] ?? '') === 'HR')) {
                $score += 4;
            }
            if ($wantsAttendance && $key === 'attendance') {
                $score += 8;
            }
            if ($score > 0) {
                $sourceScores[$key] = $score;
            }
        }

        arsort($sourceScores);
        $maxSources = min(2, (int) ($schema['max_sources'] ?? 4));
        $sources = array_slice(array_keys($sourceScores), 0, $maxSources);

        if ($sources === []) {
            // Sensible workspace defaults.
            foreach (['sales', 'sale_items', 'products', 'customers', 'employees'] as $fallback) {
                if (collect($schema['sources'] ?? [])->contains(fn ($s) => ($s['key'] ?? null) === $fallback)) {
                    $sources = [$fallback];
                    break;
                }
            }
            if ($sources === [] && ! empty($schema['sources'][0]['key'])) {
                $sources = [(string) $schema['sources'][0]['key']];
            }
        }

        // Prefer sale_items (+ products) when the ask is about products sold.
        if ($wantsProduct && in_array('sale_items', array_column($schema['sources'] ?? [], 'key'), true)) {
            $sources = array_values(array_unique(array_merge(['sale_items'], $sources)));
            $sources = array_slice($sources, 0, $maxSources);
            if (in_array('products', array_column($schema['sources'] ?? [], 'key'), true) && count($sources) < $maxSources) {
                $sources[] = 'products';
            }
        }

        $fieldCandidates = [];
        foreach ($schema['sources'] ?? [] as $source) {
            $sourceKey = (string) ($source['key'] ?? '');
            if (! in_array($sourceKey, $sources, true)) {
                continue;
            }
            foreach ($source['fields'] ?? [] as $field) {
                $fieldKey = (string) ($field['key'] ?? '');
                if ($fieldKey === '') {
                    continue;
                }
                $label = (string) ($field['label'] ?? $fieldKey);
                $haystack = mb_strtolower($fieldKey.' '.str_replace('_', ' ', $fieldKey).' '.$label.' '.($field['type'] ?? ''));
                $score = $this->overlapScore($tokens, $haystack);
                $score += $this->fieldKeywordBoost($fieldKey, $field, $tokenSet, $text, $wantsSummary, $wantsUnpaid, $wantsPaid, $wantsDaily);

                // Always keep a light baseline so every selected source contributes something useful.
                if ($score <= 0 && in_array($fieldKey, ['sale_day', 'order_total', 'product_name', 'customer_name', 'branch_name', 'quantity', 'qty', 'payment_status'], true)) {
                    $score = 1;
                }

                if ($score > 0) {
                    $fieldCandidates[] = [
                        'score' => $score,
                        'source' => $sourceKey,
                        'field' => $fieldKey,
                        'label' => $label,
                        'type' => $field['type'] ?? 'string',
                        'aggregates' => array_values($field['aggregates'] ?? []),
                        'groupable' => (bool) ($field['groupable'] ?? false),
                    ];
                }
            }
        }

        usort($fieldCandidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        $maxColumns = $schema['max_columns'] ?? null;
        $columnLimit = $maxColumns !== null && $maxColumns > 0 ? min((int) $maxColumns, 10) : 8;
        $picked = [];
        $seen = [];
        foreach ($fieldCandidates as $candidate) {
            $ref = $candidate['source'].':'.$candidate['field'];
            if (isset($seen[$ref])) {
                continue;
            }
            $seen[$ref] = true;
            $picked[] = $candidate;
            if (count($picked) >= $columnLimit) {
                break;
            }
        }

        // Ensure at least one money/number metric when summarizing.
        if ($wantsSummary) {
            $hasMetric = collect($picked)->contains(fn ($c) => in_array($c['type'], ['money', 'number'], true) && $c['aggregates'] !== []);
            if (! $hasMetric) {
                foreach ($fieldCandidates as $candidate) {
                    if (in_array($candidate['type'], ['money', 'number'], true) && $candidate['aggregates'] !== []) {
                        $picked[] = $candidate;
                        break;
                    }
                }
            }
        }

        $columns = [];
        $groupBy = [];
        $multi = count($sources) > 1;
        foreach ($picked as $candidate) {
            $aggregate = null;
            if ($wantsSummary && $candidate['aggregates'] !== [] && ! $candidate['groupable']) {
                $aggregate = in_array('sum', $candidate['aggregates'], true) ? 'sum' : $candidate['aggregates'][0];
            } elseif ($wantsSummary && $candidate['aggregates'] !== [] && in_array($candidate['field'], ['order_total', 'amount_paid', 'quantity', 'qty', 'line_total'], true)) {
                $aggregate = in_array('sum', $candidate['aggregates'], true) ? 'sum' : $candidate['aggregates'][0];
            }

            $columns[] = [
                'source' => $candidate['source'],
                'field' => $candidate['field'],
                'label' => $candidate['label'],
                ...($aggregate ? ['aggregate' => $aggregate] : []),
            ];

            if ($candidate['groupable'] && ($wantsSummary || $wantsDaily || $this->textMentionsField($text, $candidate))) {
                $groupBy[] = $multi
                    ? ['source' => $candidate['source'], 'field' => $candidate['field']]
                    : $candidate['field'];
            }
        }

        // Cap group-by to a few dimensions.
        $groupBy = array_slice($groupBy, 0, 3);

        $name = $this->titleFromInstruction($instruction);
        $description = mb_substr($instruction, 0, 240);

        return [
            'name' => $name,
            'description' => $description,
            'sources' => $sources,
            'columns' => $columns,
            'group_by' => $groupBy,
            'blend_by' => null,
        ];
    }

    /** @return list<string> */
    protected function tokenize(string $text): array
    {
        $parts = preg_split('/[^a-z0-9]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = array_flip([
            'a', 'an', 'the', 'and', 'or', 'of', 'to', 'for', 'in', 'on', 'with', 'from', 'by',
            'me', 'my', 'i', 'we', 'our', 'need', 'want', 'show', 'report', 'please', 'that',
            'this', 'those', 'these', 'all', 'any', 'into', 'over', 'under', 'about',
        ]);

        return array_values(array_filter(
            $parts,
            fn ($t) => strlen($t) > 1 && ! isset($stop[$t])
        ));
    }

    protected function textHasAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $tokens */
    protected function overlapScore(array $tokens, string $haystack): int
    {
        $score = 0;
        foreach ($tokens as $token) {
            if (str_contains($haystack, $token)) {
                $score += strlen($token) >= 5 ? 3 : 2;
            }
        }

        return $score;
    }

    /** @param  array<string, bool>  $tokenSet */
    protected function sourceKeywordBoost(string $sourceKey, array $tokenSet, string $text): int
    {
        $map = [
            'sales' => ['sale', 'sales', 'revenue', 'order', 'orders', 'till', 'pos', 'receipt', 'invoice'],
            'sale_items' => ['item', 'items', 'line', 'sku'],
            'products' => ['product', 'products', 'catalogue', 'catalog', 'sku'],
            'customers' => ['customer', 'customers', 'debtor', 'client'],
            'branches' => ['branch', 'branches', 'store', 'outlet'],
            'suppliers' => ['supplier', 'suppliers', 'vendor'],
            'stock_movements' => ['stock', 'inventory', 'movement'],
            'employees' => ['employee', 'employees', 'staff', 'payroll'],
            'attendance' => ['attendance', 'clock', 'absent', 'late', 'check'],
        ];

        $score = 0;
        foreach ($map[$sourceKey] ?? [] as $word) {
            if (isset($tokenSet[$word]) || str_contains($text, $word)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, bool>  $tokenSet
     */
    protected function fieldKeywordBoost(
        string $fieldKey,
        array $field,
        array $tokenSet,
        string $text,
        bool $wantsSummary,
        bool $wantsUnpaid,
        bool $wantsPaid,
        bool $wantsDaily,
    ): int {
        $score = 0;
        $boosts = [
            'sale_day' => ['day', 'daily', 'date'],
            'order_total' => ['total', 'totals', 'amount', 'revenue', 'sales'],
            'amount_paid' => ['paid', 'payment', 'collected'],
            'payment_status' => ['status', 'paid', 'unpaid', 'owing'],
            'product_name' => ['product', 'products', 'item', 'sku'],
            'customer_name' => ['customer', 'customers', 'client'],
            'branch_name' => ['branch', 'branches', 'store'],
            'quantity' => ['qty', 'quantity', 'units'],
            'qty' => ['qty', 'quantity', 'units'],
            'line_total' => ['line', 'total'],
            'channel' => ['channel', 'pos', 'mobile'],
        ];

        foreach ($boosts[$fieldKey] ?? [] as $word) {
            if (isset($tokenSet[$word]) || str_contains($text, $word)) {
                $score += 3;
            }
        }

        if ($wantsDaily && in_array($fieldKey, ['sale_day', 'completed_at', 'created_at'], true)) {
            $score += 5;
        }
        if ($wantsUnpaid && $fieldKey === 'payment_status') {
            $score += 6;
        }
        if ($wantsPaid && $fieldKey === 'amount_paid') {
            $score += 4;
        }
        if ($wantsSummary && in_array($field['type'] ?? '', ['money', 'number'], true)) {
            $score += 2;
        }

        return $score;
    }

    /** @param  array{field: string, label: string}  $candidate */
    protected function textMentionsField(string $text, array $candidate): bool
    {
        $needles = [
            mb_strtolower($candidate['field']),
            str_replace('_', ' ', mb_strtolower($candidate['field'])),
            mb_strtolower($candidate['label']),
        ];

        return $this->textHasAny($text, $needles);
    }

    protected function titleFromInstruction(string $instruction): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $instruction) ?? '');
        $clean = preg_replace('/^(show me|i need|please|create|build|make)\s+/iu', '', $clean) ?? $clean;
        $words = preg_split('/\s+/u', $clean, 8, PREG_SPLIT_NO_EMPTY) ?: [];
        $title = implode(' ', $words);
        if ($title === '') {
            return 'Custom report';
        }

        return mb_substr(mb_convert_case($title, MB_CASE_TITLE, 'UTF-8'), 0, 80);
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $compactSchema
     * @return array<string, mixed>
     */
    protected function askModel(array $runtime, string $instruction, array $compactSchema): array
    {
        $system = <<<'PROMPT'
You are Centrix Report Builder assistant. Pick the best data sources and columns for the user's report request.
Use ONLY keys from the provided schema JSON. Do not invent source or field keys.
Respond with a single JSON object only (no markdown):
{
  "name": "short report title",
  "description": "one sentence description",
  "sources": ["source_key"],
  "columns": [{"source": "source_key", "field": "field_key", "aggregate": null}],
  "group_by": [],
  "blend_by": null,
  "relative_date": null,
  "product_queries": []
}
Rules:
- Prefer 1 source unless the request clearly needs related tables.
- For product sales / items sold, prefer sale_items (+ products if needed) with product_name, quantity/qty, and line revenue totals.
- Prefer label fields (names, dates, status) plus the key numeric totals the user asked for.
- Use aggregate only when summarizing (sum/avg/count/max/min) and only if that field lists it in aggregates.
- group_by: string field keys for a single source, or {source, field} objects for multi-source; omit when not needed.
- blend_by: only a blend dimension key when comparing unrelated sources side-by-side; otherwise null.
- relative_date: "yesterday", "today", or "last_7_days" when the user mentions those; otherwise null.
- product_queries: short product name fragments the user wants filtered (e.g. ["Al Eman","Sugar","Polished"]); empty array if none.
- Keep columns focused (typically 4–10). Never exceed max_sources / max_columns from the schema.
PROMPT;

        try {
            $provider = $this->providers->make($runtime);
            $turn = $provider->chat([
                'system' => $system,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "Report request:\n{$instruction}\n\nSCHEMA:\n"
                            .json_encode($compactSchema, JSON_UNESCAPED_UNICODE),
                    ],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => (int) config('ai.defaults.max_tokens', config('ai.defaults.max_output_tokens', 1200)),
            ]);
        } catch (AiProviderException $e) {
            Log::warning('Report builder AI suggest provider error', [
                'provider' => $runtime['provider'] ?? null,
                'code' => $e->codeKey,
                'message' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'instruction' => [$e->getMessage()],
            ]);
        } catch (\Throwable $e) {
            Log::error('Report builder AI suggest failed', [
                'provider' => $runtime['provider'] ?? null,
                'message' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'instruction' => ['Could not reach the AI provider. Check Admin → Settings → AI.'],
            ]);
        }

        $content = trim((string) ($turn['text'] ?? ''));
        $parsed = $this->extractJsonObject($content);
        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'instruction' => ['AI returned an invalid suggestion. Try rephrasing your request.'],
            ]);
        }

        return $parsed;
    }

    /** @return array<string, mixed>|null */
    protected function extractJsonObject(string $text): ?array
    {
        if ($text === '') {
            return null;
        }

        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $schema
     * @return array{name: string, description: string|null, spec: array<string, mixed>}
     */
    protected function normalizeDraft(array $draft, array $schema, ?string $workspaceId): array
    {
        $sourceIndex = [];
        foreach ($schema['sources'] ?? [] as $source) {
            $fields = [];
            foreach ($source['fields'] ?? [] as $field) {
                $fields[$field['key']] = $field;
            }
            $sourceIndex[$source['key']] = [
                'label' => $source['label'] ?? $source['key'],
                'fields' => $fields,
            ];
        }

        $maxSources = (int) ($schema['max_sources'] ?? 4);
        $maxColumns = $schema['max_columns'] ?? null;

        $sources = [];
        foreach ((array) ($draft['sources'] ?? []) as $key) {
            $key = (string) $key;
            if ($key === '' || ! isset($sourceIndex[$key])) {
                continue;
            }
            if (! in_array($key, $sources, true)) {
                $sources[] = $key;
            }
            if (count($sources) >= $maxSources) {
                break;
            }
        }

        $columns = [];
        foreach ((array) ($draft['columns'] ?? []) as $col) {
            if (! is_array($col)) {
                continue;
            }
            $sourceKey = (string) ($col['source'] ?? ($sources[0] ?? ''));
            $fieldKey = (string) ($col['field'] ?? '');
            if ($sourceKey === '' || $fieldKey === '') {
                continue;
            }
            if (! isset($sourceIndex[$sourceKey]['fields'][$fieldKey])) {
                // Allow model to omit source when a single source is selected / discoverable.
                $resolved = $this->resolveFieldSource($fieldKey, $sources, $sourceIndex);
                if (! $resolved) {
                    continue;
                }
                $sourceKey = $resolved;
            }
            if (! in_array($sourceKey, $sources, true)) {
                if (count($sources) >= $maxSources) {
                    continue;
                }
                $sources[] = $sourceKey;
            }

            $fieldMeta = $sourceIndex[$sourceKey]['fields'][$fieldKey] ?? null;
            if (! $fieldMeta) {
                continue;
            }

            $aggregate = $col['aggregate'] ?? null;
            if ($aggregate !== null && $aggregate !== '') {
                $allowed = $fieldMeta['aggregates'] ?? [];
                if (! in_array($aggregate, $allowed, true)) {
                    $aggregate = $allowed[0] ?? null;
                }
            } else {
                $aggregate = null;
            }

            $columns[] = [
                'source' => $sourceKey,
                'field' => $fieldKey,
                'label' => $fieldMeta['label'] ?? $fieldKey,
                ...($aggregate ? ['aggregate' => $aggregate] : []),
            ];

            if ($maxColumns !== null && $maxColumns > 0 && count($columns) >= (int) $maxColumns) {
                break;
            }
        }

        $columns = $this->pruneMasterDuplicates($columns, $sources);

        if ($sources === [] || $columns === []) {
            throw ValidationException::withMessages([
                'instruction' => ['Could not match your request to available report columns. Try being more specific.'],
            ]);
        }

        $groupBy = $this->normalizeGroupBy($draft['group_by'] ?? [], $sources, $sourceIndex);
        $blendBy = null;
        $requestedBlend = $draft['blend_by'] ?? null;
        if (is_string($requestedBlend) && $requestedBlend !== '' && count($sources) > 1) {
            foreach ($schema['blend_dimensions'] ?? [] as $dim) {
                if (($dim['key'] ?? null) === $requestedBlend) {
                    $dimSources = $dim['sources'] ?? [];
                    if (collect($sources)->every(fn ($s) => in_array($s, $dimSources, true))) {
                        $blendBy = $requestedBlend;
                    }
                    break;
                }
            }
        }

        $spec = [
            'source' => $sources[0],
            'sources' => $sources,
            'blend_by' => $blendBy,
            'columns' => $columns,
            'group_by' => $blendBy ? [] : $groupBy,
            'sort' => null,
            'charts' => [],
            'kpis' => [],
        ];

        try {
            $spec = $this->builder->validateSpec($spec, $workspaceId);
        } catch (ValidationException $e) {
            // Drop blend/group and retry with columns only — still useful for the UI.
            $spec = [
                'source' => $sources[0],
                'sources' => [$sources[0]],
                'blend_by' => null,
                'columns' => array_values(array_filter(
                    $columns,
                    fn ($col) => ($col['source'] ?? null) === $sources[0]
                )),
                'group_by' => [],
                'sort' => null,
                'charts' => [],
                'kpis' => [],
            ];
            if ($spec['columns'] === []) {
                throw $e;
            }
            $spec = $this->builder->validateSpec($spec, $workspaceId);
        }

        $name = trim((string) ($draft['name'] ?? ''));
        if ($name === '') {
            $name = ($sourceIndex[$sources[0]]['label'] ?? 'Custom').' report';
        }
        $description = trim((string) ($draft['description'] ?? ''));

        return [
            'name' => mb_substr($name, 0, 200),
            'description' => $description !== '' ? mb_substr($description, 0, 2000) : null,
            'spec' => $spec,
        ];
    }

    /**
     * @param  list<string>  $sources
     * @param  array<string, array{label: string, fields: array<string, mixed>}>  $sourceIndex
     */
    protected function resolveFieldSource(string $fieldKey, array $sources, array $sourceIndex): ?string
    {
        $master = self::MASTER_FIELDS[$fieldKey] ?? null;
        if ($master && isset($sourceIndex[$master]['fields'][$fieldKey])) {
            if ($sources === [] || in_array($master, $sources, true)) {
                return $master;
            }
        }

        foreach ($sources as $sourceKey) {
            if (isset($sourceIndex[$sourceKey]['fields'][$fieldKey])) {
                return $sourceKey;
            }
        }

        foreach ($sourceIndex as $sourceKey => $meta) {
            if (isset($meta['fields'][$fieldKey])) {
                return $sourceKey;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  list<string>  $sources
     * @return list<array<string, mixed>>
     */
    protected function pruneMasterDuplicates(array $columns, array $sources): array
    {
        $selected = array_flip($sources);

        return array_values(array_filter($columns, function (array $col) use ($selected) {
            $field = $col['field'] ?? '';
            $master = self::MASTER_FIELDS[$field] ?? null;
            if (! $master || ! isset($selected[$master])) {
                return true;
            }

            return ($col['source'] ?? null) === $master;
        }));
    }

    /**
     * @param  mixed  $groupBy
     * @param  list<string>  $sources
     * @param  array<string, array{label: string, fields: array<string, mixed>}>  $sourceIndex
     * @return list<string|array{source: string, field: string}>
     */
    protected function normalizeGroupBy(mixed $groupBy, array $sources, array $sourceIndex): array
    {
        if (! is_array($groupBy) || $groupBy === []) {
            return [];
        }

        $multi = count($sources) > 1;
        $out = [];
        foreach ($groupBy as $entry) {
            if (is_string($entry)) {
                $fieldKey = $entry;
                $sourceKey = $this->resolveFieldSource($fieldKey, $sources, $sourceIndex);
            } elseif (is_array($entry)) {
                $fieldKey = (string) ($entry['field'] ?? '');
                $sourceKey = (string) ($entry['source'] ?? '');
                if ($sourceKey === '' || ! isset($sourceIndex[$sourceKey]['fields'][$fieldKey])) {
                    $sourceKey = (string) ($this->resolveFieldSource($fieldKey, $sources, $sourceIndex) ?? '');
                }
            } else {
                continue;
            }

            if ($sourceKey === '' || $fieldKey === '') {
                continue;
            }
            $fieldMeta = $sourceIndex[$sourceKey]['fields'][$fieldKey] ?? null;
            if (! $fieldMeta || ! ($fieldMeta['groupable'] ?? false)) {
                continue;
            }
            if (! in_array($sourceKey, $sources, true)) {
                continue;
            }

            $out[] = $multi
                ? ['source' => $sourceKey, 'field' => $fieldKey]
                : $fieldKey;
        }

        return array_values($out);
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, mixed>  $draft
     * @param  list<string>|null  $selectedProductCodes
     * @return array<string, mixed>
     */
    protected function attachFiltersAndProducts(
        array $normalized,
        string $instruction,
        User $user,
        array $draft,
        ?array $selectedProductCodes = null,
    ): array {
        $filters = $this->resolveDateFilters($instruction, $draft);
        $productQueries = $this->resolveProductQueries($instruction, $draft);
        $selected = array_values(array_unique(array_filter(array_map(
            static fn ($code) => trim((string) $code),
            is_array($selectedProductCodes) ? $selectedProductCodes : [],
        ), static fn ($code) => $code !== '')));

        $resolution = [
            'status' => 'none',
            'queries' => [],
            'unmatched' => [],
            'matched_codes' => [],
        ];

        if ($productQueries !== [] || $selected !== []) {
            $resolution = $this->resolveProductsForOrganization(
                (int) $user->organization_id,
                $productQueries,
                $selected,
            );
        }

        if (($resolution['status'] ?? '') === 'ready' && ($resolution['matched_codes'] ?? []) !== []) {
            $filters['product_codes'] = $resolution['matched_codes'];
        }

        $normalized['filters'] = $filters;
        $normalized['product_resolution'] = $resolution;
        $normalized['needs_product_selection'] = ($resolution['status'] ?? '') === 'needs_selection';

        if ($normalized['needs_product_selection']) {
            $normalized['message'] = 'Several products matched your description. Pick the ones to include, then apply.';
        } elseif (($resolution['unmatched'] ?? []) !== []) {
            $normalized['message'] = 'Could not find catalog matches for: '.implode(', ', $resolution['unmatched']).'.';
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, string>
     */
    protected function resolveDateFilters(string $instruction, array $draft): array
    {
        $relative = strtolower(trim((string) ($draft['relative_date'] ?? '')));
        $text = mb_strtolower($instruction);
        if ($relative === '' || ! in_array($relative, ['today', 'yesterday', 'last_7_days'], true)) {
            if (preg_match('/\byesterday\'?s?\b/u', $text)) {
                $relative = 'yesterday';
            } elseif (preg_match('/\btoday\'?s?\b/u', $text)) {
                $relative = 'today';
            } elseif (preg_match('/\blast\s*7\s*days?\b/u', $text)) {
                $relative = 'last_7_days';
            }
        }

        $now = Carbon::now(AppTimezone::name());
        return match ($relative) {
            'yesterday' => [
                'from_date' => $now->copy()->subDay()->toDateString(),
                'to_date' => $now->copy()->subDay()->toDateString(),
            ],
            'today' => [
                'from_date' => $now->toDateString(),
                'to_date' => $now->toDateString(),
            ],
            'last_7_days' => [
                'from_date' => $now->copy()->subDays(6)->toDateString(),
                'to_date' => $now->toDateString(),
            ],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return list<string>
     */
    protected function resolveProductQueries(string $instruction, array $draft): array
    {
        $fromDraft = [];
        foreach ((array) ($draft['product_queries'] ?? []) as $query) {
            $query = trim((string) $query);
            if ($query !== '') {
                $fromDraft[] = $query;
            }
        }
        if ($fromDraft !== []) {
            return array_values(array_unique($fromDraft));
        }

        return $this->extractProductQueriesFromText($instruction);
    }

    /** @return list<string> */
    protected function extractProductQueriesFromText(string $instruction): array
    {
        $text = trim($instruction);
        $text = preg_replace(
            '/\b(according to |for |from )?(yesterday\'?s?|today\'?s?|last\s+\d+\s+days?)(\s+sales)?\b/iu',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/^(i need |please |show me |create |build |make )?(a |an )?(report )?(only )?(for )?(several |some |these |the )?(products?|items?|skus?)[,:\s]*/iu',
            '',
            $text,
        ) ?? $text;
        $text = preg_replace('/\b(products?|items?|skus?|sales|report|only|several|named|called)\b/iu', ' ', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|;|\band\b|\+|\/)\s*/iu', $text) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim((string) $part, " \t\n\r\0\x0B\"'");
            $part = preg_replace('/^(the|a|an)\s+/iu', '', $part) ?? $part;
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            if (preg_match('/^(yesterday|today|sales|report|product|products)$/iu', $part)) {
                continue;
            }
            $out[] = $part;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $queries
     * @param  list<string>  $selectedCodes
     * @return array{status: string, queries: list<array<string, mixed>>, unmatched: list<string>, matched_codes: list<string>}
     */
    protected function resolveProductsForOrganization(int $organizationId, array $queries, array $selectedCodes): array
    {
        if ($selectedCodes !== []) {
            return [
                'status' => 'ready',
                'queries' => [],
                'unmatched' => [],
                'matched_codes' => $selectedCodes,
            ];
        }

        if ($queries === [] || ! Schema::hasTable('products')) {
            return [
                'status' => 'none',
                'queries' => [],
                'unmatched' => [],
                'matched_codes' => [],
            ];
        }

        $groups = [];
        $matchedCodes = [];
        $unmatched = [];
        $needsSelection = false;

        foreach ($queries as $query) {
            $matches = $this->searchProducts($organizationId, $query);
            if ($matches === []) {
                $unmatched[] = $query;
                $groups[] = [
                    'query' => $query,
                    'matches' => [],
                ];
                continue;
            }

            if (count($matches) === 1) {
                $matchedCodes[] = $matches[0]['product_code'];
                $groups[] = [
                    'query' => $query,
                    'matches' => $matches,
                    'auto_selected' => true,
                ];
                continue;
            }

            $needsSelection = true;
            $groups[] = [
                'query' => $query,
                'matches' => $matches,
            ];
        }

        if ($needsSelection) {
            return [
                'status' => 'needs_selection',
                'queries' => $groups,
                'unmatched' => $unmatched,
                'matched_codes' => array_values(array_unique($matchedCodes)),
            ];
        }

        if ($matchedCodes === [] && $unmatched !== []) {
            return [
                'status' => 'unmatched',
                'queries' => $groups,
                'unmatched' => $unmatched,
                'matched_codes' => [],
            ];
        }

        return [
            'status' => $matchedCodes !== [] ? 'ready' : 'none',
            'queries' => $groups,
            'unmatched' => $unmatched,
            'matched_codes' => array_values(array_unique($matchedCodes)),
        ];
    }

    /**
     * @return list<array{product_code: string, product_name: string, score: int}>
     */
    protected function searchProducts(int $organizationId, string $query): array
    {
        $needle = mb_strtolower(trim($query));
        if ($needle === '') {
            return [];
        }

        $rows = DB::table('products')
            ->where('organization_id', $organizationId)
            ->where(function ($q) use ($needle) {
                $q->whereRaw('LOWER(product_name) LIKE ?', ['%'.$needle.'%'])
                    ->orWhereRaw('LOWER(product_code) LIKE ?', ['%'.$needle.'%']);
            })
            ->orderBy('product_name')
            ->limit(12)
            ->get(['product_code', 'product_name']);

        $scored = [];
        foreach ($rows as $row) {
            $name = mb_strtolower((string) $row->product_name);
            $code = mb_strtolower((string) $row->product_code);
            $score = 1;
            if ($name === $needle || $code === $needle) {
                $score = 100;
            } elseif (str_starts_with($name, $needle) || str_starts_with($code, $needle)) {
                $score = 50;
            } elseif (str_contains($name, $needle) || str_contains($code, $needle)) {
                $score = 20;
            }
            $scored[] = [
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'score' => $score,
            ];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, 8);
    }
}
