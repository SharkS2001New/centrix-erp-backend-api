<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeEntry;
use App\Models\User;

class AiKnowledgeService
{
    /** @return list<array<string, mixed>> */
    public function confirmedForOrganization(int $organizationId, int $limit = 30): array
    {
        return AiKnowledgeEntry::query()
            ->where('organization_id', $organizationId)
            ->where('confirmed', true)
            ->orderByDesc('confirmed_at')
            ->limit($limit)
            ->get(['topic', 'path', 'content', 'source', 'confirmed_at'])
            ->map(fn (AiKnowledgeEntry $row) => [
                'topic' => $row->topic,
                'path' => $row->path,
                'content' => $row->content,
                'source' => $row->source,
                'confirmed_at' => $row->confirmed_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    public function teach(User $user, string $topic, string $content, ?string $path = null): array
    {
        $entry = AiKnowledgeEntry::create([
            'organization_id' => $user->organization_id,
            'created_by' => $user->id,
            'source' => 'user_teaching',
            'topic' => $topic,
            'path' => $path,
            'content' => $content,
            'confirmed' => true,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        return $this->formatEntry($entry);
    }

    /** @return array<string, mixed> */
    public function storeDraft(User $user, string $topic, string $content, string $source, ?string $path = null): array
    {
        $entry = AiKnowledgeEntry::create([
            'organization_id' => $user->organization_id,
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
            ->where('organization_id', $user->organization_id)
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
            ->where('organization_id', $user->organization_id)
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
            'content' => $entry->content,
            'source' => $entry->source,
            'confirmed' => $entry->confirmed,
            'confirmed_at' => $entry->confirmed_at?->toIso8601String(),
        ];
    }
}
