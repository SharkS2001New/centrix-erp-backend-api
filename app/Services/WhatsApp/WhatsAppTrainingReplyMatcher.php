<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappBotTrainingReply;
use Illuminate\Support\Collection;

class WhatsAppTrainingReplyMatcher
{
    /**
     * Find the best active training reply for a customer message.
     *
     * @return array{id: int, title: string|null, response_text: string, matched_keywords: list<string>, score: int}|null
     */
    public function match(string $message): ?array
    {
        $haystack = $this->normalize($message);
        if ($haystack === '') {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $bestPriority = -1;

        foreach ($this->activeReplies() as $reply) {
            $keywords = $this->normalizeKeywords($reply->keywords ?? []);
            if ($keywords === []) {
                continue;
            }

            $matched = [];
            foreach ($keywords as $keyword) {
                if ($this->containsKeyword($haystack, $keyword)) {
                    $matched[] = $keyword;
                }
            }

            $mode = strtolower((string) ($reply->match_mode ?: 'any'));
            if ($mode === 'all' && count($matched) < count($keywords)) {
                continue;
            }
            if ($matched === []) {
                continue;
            }

            $score = $this->scoreMatch($matched);
            $priority = (int) $reply->priority;

            if (
                $score > $bestScore
                || ($score === $bestScore && $priority > $bestPriority)
            ) {
                $bestScore = $score;
                $bestPriority = $priority;
                $best = [
                    'id' => (int) $reply->id,
                    'title' => $reply->title,
                    'response_text' => (string) $reply->response_text,
                    'matched_keywords' => $matched,
                    'score' => $score,
                ];
            }
        }

        return $best;
    }

    /** @return list<array<string, mixed>> */
    public function listForAdmin(): array
    {
        return WhatsappBotTrainingReply::query()
            ->orderByDesc('priority')
            ->orderBy('title')
            ->orderBy('id')
            ->get()
            ->map(fn (WhatsappBotTrainingReply $row) => $this->format($row))
            ->all();
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data, ?int $actorId = null): array
    {
        $row = WhatsappBotTrainingReply::query()->create([
            'title' => $data['title'] ?? null,
            'keywords' => $this->normalizeKeywords($data['keywords'] ?? []),
            'response_text' => trim((string) ($data['response_text'] ?? '')),
            'match_mode' => ($data['match_mode'] ?? 'any') === 'all' ? 'all' : 'any',
            'priority' => (int) ($data['priority'] ?? 100),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        return $this->format($row);
    }

    /** @param  array<string, mixed>  $data */
    public function update(WhatsappBotTrainingReply $row, array $data, ?int $actorId = null): array
    {
        if (array_key_exists('title', $data)) {
            $row->title = $data['title'];
        }
        if (array_key_exists('keywords', $data)) {
            $row->keywords = $this->normalizeKeywords($data['keywords'] ?? []);
        }
        if (array_key_exists('response_text', $data)) {
            $row->response_text = trim((string) $data['response_text']);
        }
        if (array_key_exists('match_mode', $data)) {
            $row->match_mode = ($data['match_mode'] ?? 'any') === 'all' ? 'all' : 'any';
        }
        if (array_key_exists('priority', $data)) {
            $row->priority = (int) $data['priority'];
        }
        if (array_key_exists('is_active', $data)) {
            $row->is_active = (bool) $data['is_active'];
        }
        $row->updated_by = $actorId;
        $row->save();

        return $this->format($row->fresh());
    }

    public function delete(WhatsappBotTrainingReply $row): void
    {
        $row->delete();
    }

    /** @return Collection<int, WhatsappBotTrainingReply> */
    protected function activeReplies(): Collection
    {
        return WhatsappBotTrainingReply::query()
            ->where('is_active', true)
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<string>|string  $keywords
     * @return list<string>
     */
    public function normalizeKeywords(array|string $keywords): array
    {
        if (is_string($keywords)) {
            $keywords = preg_split('/[\n,;]+/', $keywords) ?: [];
        }

        $out = [];
        foreach ($keywords as $keyword) {
            $normalized = $this->normalize((string) $keyword);
            if ($normalized === '' || mb_strlen($normalized) < 2) {
                continue;
            }
            $out[$normalized] = $normalized;
        }

        return array_values($out);
    }

    protected function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text;
    }

    protected function containsKeyword(string $haystack, string $keyword): bool
    {
        if ($keyword === '') {
            return false;
        }

        // Prefer whole-word-ish match for short keywords; substring for longer phrases.
        if (mb_strlen($keyword) <= 3) {
            return (bool) preg_match(
                '/(^|[^a-z0-9])'.preg_quote($keyword, '/').'([^a-z0-9]|$)/u',
                $haystack,
            );
        }

        return str_contains($haystack, $keyword);
    }

    /** @param  list<string>  $matched */
    protected function scoreMatch(array $matched): int
    {
        $score = count($matched) * 10;
        foreach ($matched as $keyword) {
            $score += mb_strlen($keyword);
        }

        return $score;
    }

    /** @return array<string, mixed> */
    protected function format(WhatsappBotTrainingReply $row): array
    {
        return [
            'id' => (int) $row->id,
            'title' => $row->title,
            'keywords' => array_values($row->keywords ?? []),
            'response_text' => (string) $row->response_text,
            'match_mode' => (string) ($row->match_mode ?: 'any'),
            'priority' => (int) $row->priority,
            'is_active' => (bool) $row->is_active,
            'created_at' => optional($row->created_at)?->toIso8601String(),
            'updated_at' => optional($row->updated_at)?->toIso8601String(),
        ];
    }
}
