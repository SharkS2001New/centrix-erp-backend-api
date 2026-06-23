<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;

class AiKnowledgeService
{
    /** Platform-wide knowledge injected into every tenant AI context. */
    public function confirmedForContext(int $limit = 30, ?string $workspaceId = null): array
    {
        return $this->globalEntryQuery($workspaceId)
            ->where('confirmed', true)
            ->orderByDesc('confirmed_at')
            ->limit($limit)
            ->get()
            ->map(fn (AiKnowledgeEntry $row) => $this->formatEntry($row))
            ->all();
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
}
