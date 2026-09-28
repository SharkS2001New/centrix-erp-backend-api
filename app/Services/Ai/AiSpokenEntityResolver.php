<?php

namespace App\Services\Ai;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Resolve spoken / typed names against an org's products, suppliers, and customers.
 *
 * Handles local / non-English names (e.g. "Kamande") and common STT garbling
 * ("command" → Kamande) via contains, similar_text, metaphone, and levenshtein.
 */
class AiSpokenEntityResolver
{
    /** @var list<string> */
    private const STOPWORDS = [
        'a', 'an', 'the', 'and', 'or', 'of', 'for', 'to', 'in', 'on', 'at', 'by', 'from',
        'with', 'about', 'is', 'are', 'was', 'were', 'be', 'been', 'do', 'does', 'did',
        'what', 'which', 'who', 'whom', 'whose', 'where', 'when', 'why', 'how', 'much',
        'many', 'any', 'some', 'all', 'our', 'we', 'i', 'me', 'my', 'you', 'your',
        'please', 'show', 'tell', 'give', 'get', 'need', 'want', 'can', 'could', 'would',
        'should', 'today', 'yesterday', 'tomorrow', 'week', 'month', 'year', 'last',
        'this', 'that', 'these', 'those', 'there', 'here', 'now', 'then',
        'sales', 'sale', 'stock', 'stocks', 'inventory', 'report', 'reports', 'summary',
        'total', 'balance', 'price', 'prices', 'quantity', 'qty', 'units', 'unit',
        'bags', 'bag', 'pcs', 'piece', 'pieces', 'create', 'make', 'add', 'new',
        'open', 'check', 'list', 'find', 'search', 'look', 'looking', 'lpo', 'order',
        'orders', 'purchase', 'purchases', 'supplier', 'suppliers', 'customer', 'customers',
        'product', 'products', 'item', 'items', 'centrix', 'assistant', 'talk', 'hello',
        'hi', 'hey', 'thanks', 'thank', 'yes', 'no', 'ok', 'okay', 'please',
        'kes', 'shillings', 'shilling', 'money', 'amount', 'value',
    ];

    /**
     * @return array{
     *   refs: list<array{type: string, id: ?string, code: ?string, label: string}>,
     *   candidates: list<array<string, mixed>>,
     *   hints: list<string>
     * }
     */
    public function resolve(User $user, string $message, bool $voiceMode = false): array
    {
        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId <= 0) {
            return ['refs' => [], 'candidates' => [], 'hints' => []];
        }

        $terms = $this->extractTerms($message);
        if ($terms === []) {
            return ['refs' => [], 'candidates' => [], 'hints' => []];
        }

        $productHits = $this->matchProducts($orgId, $terms, $voiceMode);
        $customerHits = $this->matchCustomers($orgId, $terms, $voiceMode);
        $supplierHits = $this->matchSuppliers($orgId, $terms, $voiceMode);

        $refs = [];
        $candidates = [];
        $hints = [];

        foreach ([
            'product' => $productHits,
            'customer' => $customerHits,
            'supplier' => $supplierHits,
        ] as $type => $hits) {
            if ($hits === []) {
                continue;
            }

            usort($hits, fn ($a, $b) => ($b['score'] <=> $a['score']));
            $best = $hits[0];
            $second = $hits[1] ?? null;

            $confident = $best['score'] >= ($voiceMode ? 68 : 72)
                && ($second === null || ($best['score'] - $second['score']) >= 8);

            if ($confident) {
                $refs[] = [
                    'type' => $type,
                    'id' => $best['id'] ?? null,
                    'code' => $best['code'] ?? null,
                    'label' => $best['label'],
                ];
                $hints[] = "Spoken/typed \"{$best['term']}\" matched {$type} \"{$best['label']}\" ({$best['reason']}). Use this exact record.";
            } else {
                foreach (array_slice($hits, 0, 5) as $hit) {
                    if ($hit['score'] < ($voiceMode ? 55 : 60)) {
                        continue;
                    }
                    $candidates[] = [
                        'type' => $type,
                        'term' => $hit['term'],
                        'label' => $hit['label'],
                        'code' => $hit['code'] ?? null,
                        'id' => $hit['id'] ?? null,
                        'score' => round($hit['score'], 1),
                        'reason' => $hit['reason'],
                    ];
                }
                if ($candidates !== []) {
                    $labels = array_map(fn ($c) => $c['label'], array_slice($candidates, -5));
                    $hints[] = 'Possible '.$type.' matches for "'.$best['term'].'": '
                        .implode(', ', array_unique($labels))
                        .'. If unsure, ask which one the user meant.';
                }
            }
        }

        return [
            'refs' => $this->uniqueRefs($refs),
            'candidates' => array_slice($candidates, 0, 12),
            'hints' => array_values(array_unique($hints)),
        ];
    }

    /**
     * Significant name-like terms and short phrases from a message.
     *
     * @return list<string>
     */
    public function extractTerms(string $message): array
    {
        $raw = mb_strtolower(trim($message));
        $raw = preg_replace("/[\"'`]/", ' ', $raw) ?? $raw;
        $parts = preg_split('/[^a-z0-9]+/u', $raw) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $t = trim((string) $part);
            if (mb_strlen($t) < 3) {
                continue;
            }
            if (in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            if (ctype_digit($t)) {
                continue;
            }
            $tokens[] = $t;
        }
        $tokens = array_values(array_unique($tokens));

        $terms = $tokens;
        // Bigrams help "royal kamande" style product names.
        for ($i = 0; $i < count($tokens) - 1; $i++) {
            $terms[] = $tokens[$i].' '.$tokens[$i + 1];
        }

        // Prefer longer / rarer terms first.
        usort($terms, function ($a, $b) {
            $la = mb_strlen($a);
            $lb = mb_strlen($b);

            return $lb <=> $la ?: strcmp($a, $b);
        });

        return array_slice(array_values(array_unique($terms)), 0, 12);
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{term: string, label: string, code: ?string, id: ?string, score: float, reason: string}>
     */
    protected function matchProducts(int $orgId, array $terms, bool $voiceMode): array
    {
        $rows = $this->loadCatalogRows(
            table: 'products',
            orgId: $orgId,
            nameColumn: 'product_name',
            codeColumn: 'product_code',
            idColumn: null,
            terms: $terms,
            voiceMode: $voiceMode,
            softDelete: true,
            limit: $voiceMode ? 1200 : 400,
        );

        return $this->scoreRows($terms, $rows, 'product_name', 'product_code', null);
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{term: string, label: string, code: ?string, id: ?string, score: float, reason: string}>
     */
    protected function matchCustomers(int $orgId, array $terms, bool $voiceMode): array
    {
        $rows = $this->loadCatalogRows(
            table: 'customers',
            orgId: $orgId,
            nameColumn: 'customer_name',
            codeColumn: 'customer_num',
            idColumn: null,
            terms: $terms,
            voiceMode: $voiceMode,
            softDelete: true,
            limit: $voiceMode ? 800 : 300,
        );

        return $this->scoreRows($terms, $rows, 'customer_name', 'customer_num', null);
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{term: string, label: string, code: ?string, id: ?string, score: float, reason: string}>
     */
    protected function matchSuppliers(int $orgId, array $terms, bool $voiceMode): array
    {
        $rows = $this->loadCatalogRows(
            table: 'suppliers',
            orgId: $orgId,
            nameColumn: 'supplier_name',
            codeColumn: 'supplier_code',
            idColumn: 'id',
            terms: $terms,
            voiceMode: $voiceMode,
            softDelete: true,
            limit: $voiceMode ? 600 : 250,
        );

        return $this->scoreRows($terms, $rows, 'supplier_name', 'supplier_code', 'id');
    }

    /**
     * @param  list<string>  $terms
     * @return list<object>
     */
    protected function loadCatalogRows(
        string $table,
        int $orgId,
        string $nameColumn,
        string $codeColumn,
        ?string $idColumn,
        array $terms,
        bool $voiceMode,
        bool $softDelete,
        int $limit,
    ): array {
        $select = array_values(array_filter([$idColumn, $codeColumn, $nameColumn]));

        $base = function () use ($table, $orgId, $softDelete) {
            $query = DB::table($table)->where('organization_id', $orgId);
            if ($softDelete) {
                $query->whereNull('deleted_at');
            }

            return $query;
        };

        // Always try token LIKE first (fast path for correct names).
        $likeQuery = $base();
        $likeQuery->where(function ($q) use ($terms, $nameColumn, $codeColumn) {
            foreach ($terms as $term) {
                $token = trim(explode(' ', $term)[0] ?? '');
                if (mb_strlen($token) < 3) {
                    continue;
                }
                $like = '%'.$this->escapeLike($token).'%';
                $q->orWhere($nameColumn, 'like', $like);
                if ($codeColumn !== '') {
                    $q->orWhere($codeColumn, 'like', $like);
                }
            }
        });

        $liked = $likeQuery->orderBy($nameColumn)->limit(min(120, $limit))->get($select)->all();

        // Voice / fuzzy path: pull a capped name list so metaphone can catch STT garbling.
        if ($voiceMode || count($liked) < 3) {
            $broad = $base()->orderBy($nameColumn)->limit($limit)->get($select)->all();
            $byKey = [];
            foreach (array_merge($liked, $broad) as $row) {
                $key = ($idColumn ? (string) ($row->{$idColumn} ?? '') : '')
                    .'|'.(string) ($row->{$codeColumn} ?? '')
                    .'|'.(string) ($row->{$nameColumn} ?? '');
                $byKey[$key] = $row;
            }

            return array_values($byKey);
        }

        return $liked;
    }

    /**
     * @param  list<string>  $terms
     * @param  list<object>  $rows
     * @return list<array{term: string, label: string, code: ?string, id: ?string, score: float, reason: string}>
     */
    protected function scoreRows(
        array $terms,
        array $rows,
        string $nameColumn,
        string $codeColumn,
        ?string $idColumn,
    ): array {
        $hits = [];
        foreach ($terms as $term) {
            $bestForTerm = null;
            foreach ($rows as $row) {
                $label = trim((string) ($row->{$nameColumn} ?? ''));
                $code = trim((string) ($row->{$codeColumn} ?? ''));
                if ($label === '' && $code === '') {
                    continue;
                }

                $nameScore = $this->scoreSpoken($term, $label);
                $codeScore = $code !== '' ? $this->scoreSpoken($term, $code) * 0.95 : 0;
                $score = max($nameScore, $codeScore);
                if ($score < 55) {
                    continue;
                }

                $reason = $this->matchReason($term, $score >= $codeScore && $codeScore >= $nameScore ? $code : $label);
                $hit = [
                    'term' => $term,
                    'label' => $label !== '' ? $label : $code,
                    'code' => $code !== '' ? $code : null,
                    'id' => $idColumn ? (string) ($row->{$idColumn} ?? '') : null,
                    'score' => $score,
                    'reason' => $reason,
                ];
                if ($hit['id'] === '') {
                    $hit['id'] = null;
                }
                if ($bestForTerm === null || $hit['score'] > $bestForTerm['score']) {
                    $bestForTerm = $hit;
                }
            }
            if ($bestForTerm !== null) {
                $hits[] = $bestForTerm;
            }
        }

        // Collapse duplicate labels — keep highest score.
        $byLabel = [];
        foreach ($hits as $hit) {
            $key = mb_strtolower($hit['label']).'|'.($hit['code'] ?? '');
            if (! isset($byLabel[$key]) || $hit['score'] > $byLabel[$key]['score']) {
                $byLabel[$key] = $hit;
            }
        }

        return array_values($byLabel);
    }

    public function scoreSpoken(string $needle, string $haystack): float
    {
        $n = mb_strtolower(trim($needle));
        $h = mb_strtolower(trim($haystack));
        if ($n === '' || $h === '') {
            return 0.0;
        }
        if ($n === $h) {
            return 100.0;
        }

        $score = 0.0;
        if (str_contains($h, $n)) {
            $score = max($score, 88.0 + min(10.0, mb_strlen($n) / 2));
        }
        if (str_contains($n, $h) && mb_strlen($h) >= 3) {
            $score = max($score, 80.0);
        }

        // Compare against full name and first significant word.
        $targets = [$h];
        foreach (preg_split('/\s+/', $h) ?: [] as $word) {
            if (mb_strlen($word) >= 3) {
                $targets[] = $word;
            }
        }

        foreach (array_unique($targets) as $target) {
            similar_text($n, $target, $pct);
            $score = max($score, (float) $pct);

            if (function_exists('metaphone')) {
                $mn = metaphone($n);
                $mt = metaphone($target);
                if ($mn !== '' && $mn === $mt) {
                    $score = max($score, 82.0);
                }
            }
            if (function_exists('soundex') && soundex($n) === soundex($target) && soundex($n) !== '0000') {
                $score = max($score, 70.0);
            }

            $a = mb_substr($n, 0, 255);
            $b = mb_substr($target, 0, 255);
            $maxLen = max(mb_strlen($a), mb_strlen($b));
            if ($maxLen > 0) {
                $lev = levenshtein($a, $b);
                $levScore = (1 - ($lev / $maxLen)) * 100;
                $score = max($score, $levScore * 0.92);
            }
        }

        // Token overlap bonus from AiNearMissHelper style.
        foreach (AiNearMissHelper::tokens($n) as $token) {
            if (str_contains($h, $token)) {
                $score = min(100.0, $score + 2.0);
            }
        }

        return min(100.0, $score);
    }

    protected function matchReason(string $needle, string $label): string
    {
        $n = mb_strtolower(trim($needle));
        $h = mb_strtolower(trim($label));
        if ($n === $h) {
            return 'exact name';
        }
        if (str_contains($h, $n)) {
            return 'name contains spoken text';
        }
        if (function_exists('metaphone')) {
            $first = explode(' ', $h)[0] ?? $h;
            if (metaphone($n) !== '' && metaphone($n) === metaphone($first)) {
                return 'sounds like this name (voice match)';
            }
        }
        similar_text($n, $h, $pct);
        if ($pct >= 55) {
            return 'similar spelling ('.round($pct).'% match)';
        }

        return 'closest catalog name';
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @param  list<array{type: string, id: ?string, code: ?string, label: string}>  $refs
     * @return list<array{type: string, id: ?string, code: ?string, label: string}>
     */
    protected function uniqueRefs(array $refs): array
    {
        $out = [];
        $seen = [];
        foreach ($refs as $ref) {
            $key = $ref['type'].'|'.($ref['id'] ?? '').'|'.($ref['code'] ?? '').'|'.mb_strtolower($ref['label']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $ref;
        }

        return $out;
    }
}
