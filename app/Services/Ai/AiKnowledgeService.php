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
    public function listGlobal(?string $workspaceId = null, int $limit = 100): array
    {
        return $this->globalEntryQuery($workspaceId)
            ->orderByDesc('updated_at')
            ->limit($limit)
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
        return [
            'id' => $entry->id,
            'topic' => $entry->topic,
            'path' => $entry->path,
            'workspace_id' => $entry->workspace_id,
            'content' => $entry->content,
            'source' => $entry->source,
            'scope' => $entry->organization_id === null ? 'platform' : 'organization',
            'confirmed' => $entry->confirmed,
            'confirmed_at' => $entry->confirmed_at?->toIso8601String(),
            'created_at' => $entry->created_at?->toIso8601String(),
            'updated_at' => $entry->updated_at?->toIso8601String(),
        ];
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
