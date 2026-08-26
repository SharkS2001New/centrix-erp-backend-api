<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;

class AiKnowledgeService
{
    /**
     * Platform-wide knowledge injected into tenant AI context.
     * When $message is provided, ranks notes by relevance (not only newest).
     */
    public function confirmedForContext(
        int $limit = 30,
        ?string $workspaceId = null,
        ?string $message = null,
    ): array {
        $limit = max(1, min(80, $limit));

        if ($message !== null && trim($message) !== '') {
            return $this->searchRelevant($message, $limit, $workspaceId);
        }

        return $this->globalEntryQuery($workspaceId)
            ->where('confirmed', true)
            ->orderByDesc('confirmed_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiKnowledgeEntry $row) => $this->formatEntry($row))
            ->all();
    }

    /**
     * Rank confirmed platform notes against a user question / topic.
     *
     * @return list<array<string, mixed>>
     */
    public function searchRelevant(string $message, int $limit = 12, ?string $workspaceId = null): array
    {
        $limit = max(1, min(40, $limit));
        $needle = mb_strtolower(trim($message));
        $tokens = preg_split('/[\s,;\/\-?!.\'"]+/u', $needle) ?: [];
        $tokens = array_values(array_filter(
            $tokens,
            fn ($t) => mb_strlen((string) $t) >= 2
                && ! in_array($t, ['the', 'and', 'for', 'how', 'what', 'where', 'when', 'with', 'from', 'this', 'that', 'into', 'about', 'does', 'can', 'will'], true),
        ));

        $rows = $this->globalEntryQuery($workspaceId)
            ->where('confirmed', true)
            ->orderByDesc('confirmed_at')
            ->limit(500)
            ->get();

        $scored = [];
        foreach ($rows as $row) {
            $hay = mb_strtolower(implode(' ', [
                (string) $row->topic,
                (string) $row->content,
                (string) ($row->path ?? ''),
                (string) ($row->workspace_id ?? ''),
            ]));
            $score = $this->scoreHaystack($hay, $needle, $tokens);
            if ($score <= 0) {
                continue;
            }
            $entry = $this->formatEntry($row);
            $entry['relevance'] = $score;
            $scored[] = $entry;
        }

        usort($scored, function (array $a, array $b) {
            $cmp = ($b['relevance'] ?? 0) <=> ($a['relevance'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($b['confirmed_at'] ?? ''), (string) ($a['confirmed_at'] ?? ''));
        });

        $top = array_slice($scored, 0, $limit);

        // Always keep a few newest notes so fresh training is not buried when query is vague.
        if ($top === [] || count($top) < min(5, $limit)) {
            $newest = $this->globalEntryQuery($workspaceId)
                ->where('confirmed', true)
                ->orderByDesc('confirmed_at')
                ->limit($limit)
                ->get()
                ->map(fn (AiKnowledgeEntry $row) => $this->formatEntry($row))
                ->all();
            $byId = [];
            foreach (array_merge($top, $newest) as $entry) {
                $byId[$entry['id']] = $entry;
            }
            $top = array_slice(array_values($byId), 0, $limit);
        }

        return array_map(function (array $entry) {
            unset($entry['relevance']);

            return $entry;
        }, $top);
    }

    /** @return list<array<string, mixed>> */
    public function listGlobal(?string $workspaceId = null, int $limit = 2000): array
    {
        return $this->globalEntryQuery($workspaceId)
            ->orderByDesc('updated_at')
            ->limit(max(1, min(5000, $limit)))
            ->get()
            ->map(fn (AiKnowledgeEntry $row) => $this->formatEntry($row))
            ->all();
    }

    /** @return array<string, mixed> */
    public function teachGlobal(
        User $user,
        string $topic,
        string $content,
        ?string $path = null,
        ?string $workspaceId = null,
        string $source = 'platform_training',
    ): array {
        $entry = AiKnowledgeEntry::create([
            'organization_id' => null,
            'created_by' => $user->id,
            'source' => $source,
            'topic' => $topic,
            'path' => $path,
            'workspace_id' => $workspaceId,
            'content' => $content,
            'confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        return $this->formatEntry($entry);
    }

    /**
     * Bulk-create Q&A / training notes. Skips empty rows; does not dedupe by default.
     *
     * @param  list<array{topic?: string, question?: string, content?: string, answer?: string, path?: ?string, workspace_id?: ?string}>  $rows
     * @return array{created: int, entries: list<array<string, mixed>>}
     */
    public function teachGlobalBulk(User $user, array $rows, string $source = 'platform_bulk'): array
    {
        $created = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $topic = trim((string) ($row['topic'] ?? $row['question'] ?? ''));
            $content = trim((string) ($row['content'] ?? $row['answer'] ?? ''));
            if ($topic === '' || $content === '') {
                continue;
            }
            $path = isset($row['path']) ? (trim((string) $row['path']) ?: null) : null;
            $workspaceId = isset($row['workspace_id']) ? (trim((string) $row['workspace_id']) ?: null) : null;
            $created[] = $this->teachGlobal($user, $topic, $content, $path, $workspaceId, $source);
        }

        return [
            'created' => count($created),
            'entries' => $created,
        ];
    }

    /**
     * Install curated foundation notes from config (skips topics that already exist).
     *
     * @return array{created: int, skipped: int, entries: list<array<string, mixed>>}
     */
    public function installFoundationNotes(User $user): array
    {
        $seeds = config('ai_training_foundation', []);
        $created = [];
        $skipped = 0;

        foreach ($seeds as $seed) {
            if (! is_array($seed)) {
                continue;
            }
            $topic = trim((string) ($seed['topic'] ?? ''));
            $content = trim((string) ($seed['content'] ?? ''));
            if ($topic === '' || $content === '') {
                continue;
            }

            $exists = AiKnowledgeEntry::query()
                ->whereNull('organization_id')
                ->where('topic', $topic)
                ->exists();
            if ($exists) {
                $skipped++;
                continue;
            }

            $created[] = $this->teachGlobal(
                $user,
                $topic,
                $content,
                isset($seed['path']) ? (trim((string) $seed['path']) ?: null) : null,
                isset($seed['workspace_id']) ? (trim((string) $seed['workspace_id']) ?: null) : null,
                'foundation_seed',
            );
        }

        return [
            'created' => count($created),
            'skipped' => $skipped,
            'entries' => $created,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function updateGlobal(User $user, int $entryId, array $data): ?array
    {
        $entry = $this->findGlobalEntry($entryId);
        if (! $entry) {
            return null;
        }

        $entry->update([
            'topic' => $data['topic'] ?? $entry->topic,
            'content' => $data['content'] ?? $entry->content,
            'path' => array_key_exists('path', $data) ? $data['path'] : $entry->path,
            'workspace_id' => array_key_exists('workspace_id', $data) ? $data['workspace_id'] : $entry->workspace_id,
            'confirmed' => true,
            'confirmed_at' => $entry->confirmed_at ?? now(),
            'confirmed_by' => $entry->confirmed_by ?? $user->id,
        ]);

        return $this->formatEntry($entry->fresh());
    }

    public function deleteGlobal(int $entryId): bool
    {
        return (bool) AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->whereKey($entryId)
            ->delete();
    }

    /**
     * Find clusters of platform notes with similar topics.
     *
     * @return array{threshold: float, cluster_count: int, duplicate_entry_count: int, clusters: list<array{similarity: float, entries: list<array<string, mixed>>}>}
     */
    public function findDuplicateClusters(?string $workspaceId = null, float $threshold = 85.0): array
    {
        $threshold = max(50.0, min(100.0, $threshold));
        $entries = $this->globalEntryQuery($workspaceId)
            ->orderByDesc('updated_at')
            ->limit(500)
            ->get()
            ->map(fn (AiKnowledgeEntry $row) => $this->formatEntry($row))
            ->all();

        $n = count($entries);
        $parent = range(0, max(0, $n - 1));

        $find = function (int $i) use (&$parent, &$find): int {
            if ($parent[$i] !== $i) {
                $parent[$i] = $find($parent[$i]);
            }

            return $parent[$i];
        };

        $union = function (int $a, int $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$rb] = $ra;
            }
        };

        $pairScores = [];
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $score = $this->topicSimilarity(
                    (string) ($entries[$i]['topic'] ?? ''),
                    (string) ($entries[$j]['topic'] ?? ''),
                );
                if ($score >= $threshold) {
                    $union($i, $j);
                    $pairScores["{$i}:{$j}"] = $score;
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $n; $i++) {
            $root = $find($i);
            $groups[$root][] = $i;
        }

        $clusters = [];
        foreach ($groups as $indices) {
            if (count($indices) < 2) {
                continue;
            }
            $clusterEntries = array_map(fn (int $idx) => $entries[$idx], $indices);
            usort($clusterEntries, fn ($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));

            $maxSim = 100.0;
            if (count($indices) >= 2) {
                $maxSim = 0.0;
                for ($a = 0; $a < count($indices); $a++) {
                    for ($b = $a + 1; $b < count($indices); $b++) {
                        $key = "{$indices[$a]}:{$indices[$b]}";
                        $keyRev = "{$indices[$b]}:{$indices[$a]}";
                        $sim = $pairScores[$key] ?? $pairScores[$keyRev] ?? $this->topicSimilarity(
                            (string) ($entries[$indices[$a]]['topic'] ?? ''),
                            (string) ($entries[$indices[$b]]['topic'] ?? ''),
                        );
                        $maxSim = max($maxSim, $sim);
                    }
                }
            }

            $clusters[] = [
                'similarity' => round($maxSim, 1),
                'entries' => $clusterEntries,
            ];
        }

        usort($clusters, fn ($a, $b) => ($b['similarity'] <=> $a['similarity']) ?: (count($b['entries']) <=> count($a['entries'])));

        $duplicateCount = array_sum(array_map(fn ($c) => count($c['entries']) - 1, $clusters));

        return [
            'threshold' => $threshold,
            'cluster_count' => count($clusters),
            'duplicate_entry_count' => $duplicateCount,
            'clusters' => $clusters,
        ];
    }

    /**
     * Merge duplicate notes into one kept entry; deletes the rest.
     *
     * @param  list<int>  $mergeIds
     * @return array<string, mixed>|null
     */
    public function mergeGlobal(
        User $user,
        int $keepId,
        array $mergeIds,
        ?string $topic = null,
        ?string $content = null,
    ): ?array {
        $keep = $this->findGlobalEntry($keepId);
        if (! $keep) {
            return null;
        }

        $mergeIds = array_values(array_unique(array_filter(
            array_map('intval', $mergeIds),
            fn (int $id) => $id > 0 && $id !== $keepId,
        )));

        $mergedContent = trim($content ?? '');
        if ($mergedContent === '') {
            $parts = [trim((string) $keep->content)];
            foreach ($mergeIds as $id) {
                $other = $this->findGlobalEntry($id);
                if ($other && trim((string) $other->content) !== '') {
                    $parts[] = trim((string) $other->content);
                }
            }
            $mergedContent = implode("\n\n", array_unique(array_filter($parts)));
        }

        $keep->update([
            'topic' => trim($topic ?? '') !== '' ? trim($topic) : $keep->topic,
            'content' => $mergedContent !== '' ? $mergedContent : $keep->content,
            'confirmed' => true,
            'confirmed_at' => $keep->confirmed_at ?? now(),
            'confirmed_by' => $keep->confirmed_by ?? $user->id,
        ]);

        if ($mergeIds !== []) {
            AiKnowledgeEntry::query()
                ->whereNull('organization_id')
                ->whereIn('id', $mergeIds)
                ->delete();
        }

        return $this->formatEntry($keep->fresh());
    }

    /** @param  list<int>  $entryIds */
    public function deleteGlobalBulk(array $entryIds): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entryIds), fn ($id) => $id > 0)));
        if ($ids === []) {
            return 0;
        }

        return AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->whereIn('id', $ids)
            ->delete();
    }

    /**
     * Delete all platform-wide training notes (optionally scoped to a workspace).
     */
    public function deleteGlobalAll(?string $workspaceId = null): int
    {
        return $this->globalEntryQuery($workspaceId)->delete();
    }

    protected function normalizeTopic(string $topic): string
    {
        $t = mb_strtolower(trim($topic));
        $t = preg_replace('/^(?:q|question)\s*[:\-]\s*/iu', '', $t) ?? $t;
        $t = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return trim($t);
    }

    protected function topicSimilarity(string $a, string $b): float
    {
        $na = $this->normalizeTopic($a);
        $nb = $this->normalizeTopic($b);
        if ($na === '' || $nb === '') {
            return 0.0;
        }
        if ($na === $nb) {
            return 100.0;
        }
        similar_text($na, $nb, $pct);

        return round((float) $pct, 1);
    }

    /** @deprecated Use teachGlobal — tenant users cannot add org-scoped knowledge. */
    public function teach(User $user, string $topic, string $content, ?string $path = null, ?string $workspaceId = null): array
    {
        return $this->teachGlobal($user, $topic, $content, $path, $workspaceId, 'user_teaching');
    }

    /** @return array<string, mixed> */
    public function storeDraft(User $user, string $topic, string $content, string $source, ?string $path = null): array
    {
        $entry = AiKnowledgeEntry::create([
            'organization_id' => null,
            'created_by' => $user->id,
            'source' => $source,
            'topic' => $topic,
            'path' => $path,
            'content' => $content,
            'confirmed' => false,
        ]);

        return $this->formatEntry($entry);
    }

    public function confirm(User $user, int $entryId): ?array
    {
        $entry = AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->whereKey($entryId)
            ->first();

        if (! $entry) {
            return null;
        }

        $entry->update([
            'confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        return $this->formatEntry($entry->fresh());
    }

    public function discard(User $user, int $entryId): bool
    {
        return (bool) AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->where('confirmed', false)
            ->whereKey($entryId)
            ->delete();
    }

    /** @return array<string, mixed> */
    protected function formatEntry(AiKnowledgeEntry $entry): array
    {
        $topic = (string) $entry->topic;
        $content = (string) $entry->content;

        return [
            'id' => $entry->id,
            'topic' => $topic,
            'path' => $entry->path,
            'workspace_id' => $entry->workspace_id,
            'content' => $content,
            // Explicit exemplar fields so the model does not treat Q/A as a canned reply.
            'usage' => 'exemplar',
            'sample_question' => $this->stripQaPrefix($topic),
            'sample_answer_style' => $this->stripQaPrefix($content),
            'how_to_use' => 'This is a SAMPLE of how to think and structure a reply for similar questions — '
                .'not a canned answer to paste. Keep the same approach, facts pattern, and screen paths, '
                .'but write a fresh answer for THIS user\'s question; call live tools for current org data.',
            'source' => $entry->source,
            'scope' => $entry->organization_id === null ? 'platform' : 'organization',
            'confirmed' => $entry->confirmed,
            'confirmed_at' => $entry->confirmed_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /** Strip leading "Q:" / "A:" labels often used when saving training pairs. */
    protected function stripQaPrefix(string $text): string
    {
        $trimmed = trim($text);
        $trimmed = preg_replace('/^(?:q|question|a|answer)\s*[:\-]\s*/iu', '', $trimmed) ?? $trimmed;

        return trim($trimmed);
    }

    protected function findGlobalEntry(int $entryId): ?AiKnowledgeEntry
    {
        return AiKnowledgeEntry::query()
            ->whereNull('organization_id')
            ->whereKey($entryId)
            ->first();
    }

    protected function globalEntryQuery(?string $workspaceId)
    {
        $query = AiKnowledgeEntry::query()->whereNull('organization_id');

        if ($workspaceId) {
            $query->where(function ($q) use ($workspaceId) {
                $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId);
            });
        }

        return $query;
    }

    /**
     * @param  list<string>  $tokens
     */
    protected function scoreHaystack(string $hay, string $needle, array $tokens): int
    {
        $score = 0;
        if ($needle !== '' && str_contains($hay, $needle)) {
            $score += 40;
        }
        foreach ($tokens as $token) {
            if (str_contains($hay, $token)) {
                $score += 3;
            }
        }
        // Boost notes that look like Q&A for the same intent words.
        foreach (['uom', 'kg', 'bag', 'retail', 'packaging', 'grn', 'lpo', 'pos', 'vat', 'kra', 'stock', 'measure'] as $boost) {
            if (str_contains($needle, $boost) && str_contains($hay, $boost)) {
                $score += 5;
            }
        }

        return $score;
    }
}
