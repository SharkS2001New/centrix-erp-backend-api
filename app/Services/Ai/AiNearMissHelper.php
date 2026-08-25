<?php

namespace App\Services\Ai;

/**
 * Structured "no exact match, but here's the closest" payloads for Centrix AI tools.
 */
class AiNearMissHelper
{
    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return array<string, mixed>
     */
    public static function ambiguous(string $searched, array $candidates, string $entityLabel = 'match'): array
    {
        return [
            'error' => true,
            'near_miss' => true,
            'searched_for' => $searched,
            'match_type' => 'ambiguous',
            'candidates' => $candidates,
            'message' => self::formatAmbiguous($searched, $candidates, $entityLabel),
            'tip' => 'List each candidate and ask the user to pick one by name or code.',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $closest  keys: label, reason, plus optional metadata
     * @param  list<array<string, mixed>>  $alternatives
     * @param  list<array<string, mixed>>|null  $screens
     * @return array<string, mixed>
     */
    public static function noExact(
        string $searched,
        ?array $closest = null,
        array $alternatives = [],
        ?array $screens = null,
        ?string $relatedHint = null,
    ): array {
        return [
            'error' => true,
            'near_miss' => true,
            'searched_for' => $searched,
            'match_type' => $closest !== null ? 'closest' : 'none',
            'closest_match' => $closest,
            'alternatives' => array_slice($alternatives, 0, 5),
            'screens' => $screens,
            'related_hint' => $relatedHint,
            'message' => self::formatNoExact($searched, $closest, $alternatives, $relatedHint),
            'tip' => 'Say you could not find an exact match, name the closest option with the reason, '
                .'list alternatives if any, and include Centrix screen links.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public static function formatAmbiguous(string $searched, array $candidates, string $entityLabel): string
    {
        $lines = [
            "I found several possible {$entityLabel}s for **{$searched}**, not one exact match:",
            '',
        ];
        foreach (array_slice($candidates, 0, 8) as $row) {
            $label = (string) ($row['label'] ?? $row['customer_name'] ?? $row['supplier_name']
                ?? $row['product_name'] ?? $row['name'] ?? $row['cashier_name'] ?? '—');
            $extra = self::candidateExtra($row);
            $lines[] = $extra !== '' ? "- **{$label}** ({$extra})" : "- **{$label}**";
        }
        $lines[] = '';
        $lines[] = 'Which one did you mean? Reply with the exact name or code.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>|null  $closest
     * @param  list<array<string, mixed>>  $alternatives
     */
    public static function formatNoExact(
        string $searched,
        ?array $closest,
        array $alternatives = [],
        ?string $relatedHint = null,
    ): string {
        if ($closest !== null) {
            $label = (string) ($closest['label'] ?? '—');
            $reason = trim((string) ($closest['reason'] ?? 'similar name in Centrix'));
            $lines = [
                "I couldn't find an exact match for **{$searched}**.",
                "The closest I found is **{$label}** ({$reason}).",
            ];
            if ($relatedHint !== null && $relatedHint !== '') {
                $lines[] = $relatedHint;
            }
            if ($alternatives !== []) {
                $lines[] = '';
                $lines[] = 'Other similar options:';
                foreach (array_slice($alternatives, 0, 5) as $alt) {
                    $altLabel = (string) ($alt['label'] ?? '—');
                    $altReason = trim((string) ($alt['reason'] ?? ''));
                    $lines[] = $altReason !== '' ? "- **{$altLabel}** ({$altReason})" : "- **{$altLabel}**";
                }
            }

            return implode("\n", $lines);
        }

        $lines = [
            "I couldn't find an exact match for **{$searched}** in Centrix.",
        ];
        if ($alternatives !== []) {
            $lines[] = '';
            $lines[] = 'Here are the closest names I found:';
            foreach (array_slice($alternatives, 0, 5) as $alt) {
                $altLabel = (string) ($alt['label'] ?? '—');
                $altReason = trim((string) ($alt['reason'] ?? ''));
                $lines[] = $altReason !== '' ? "- **{$altLabel}** ({$altReason})" : "- **{$altLabel}**";
            }
        }
        if ($relatedHint !== null && $relatedHint !== '') {
            $lines[] = '';
            $lines[] = $relatedHint;
        } else {
            $lines[] = '';
            $lines[] = 'Check the spelling or try a shorter search term.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{label: string, reason: string, score: int}>  $ranked
     * @return array{closest: ?array<string, mixed>, alternatives: list<array<string, mixed>>}
     */
    public static function splitRankedMatches(array $ranked): array
    {
        if ($ranked === []) {
            return ['closest' => null, 'alternatives' => []];
        }

        $closest = [
            'label' => $ranked[0]['label'],
            'reason' => $ranked[0]['reason'],
        ];
        $alternatives = [];
        foreach (array_slice($ranked, 1, 4) as $row) {
            $alternatives[] = [
                'label' => $row['label'],
                'reason' => $row['reason'],
            ];
        }

        return ['closest' => $closest, 'alternatives' => $alternatives];
    }

    /**
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        $parts = preg_split('/[\s,\/\-_]+/', mb_strtolower(trim($text))) ?: [];

        return array_values(array_filter($parts, fn ($t) => mb_strlen((string) $t) >= 2));
    }

    public static function scoreNameMatch(string $needle, string $haystack): int
    {
        $needle = mb_strtolower(trim($needle));
        $haystack = mb_strtolower(trim($haystack));
        if ($needle === '' || $haystack === '') {
            return 0;
        }

        $score = 0;
        if (str_contains($haystack, $needle)) {
            $score += 25;
        }
        foreach (self::tokens($needle) as $token) {
            if (str_contains($haystack, $token)) {
                $score += 6;
            }
        }
        similar_text($needle, $haystack, $pct);
        if ($pct >= 45) {
            $score += (int) round($pct / 5);
        }

        return $score;
    }

    public static function matchReason(string $needle, string $label): string
    {
        $needle = mb_strtolower(trim($needle));
        $labelLower = mb_strtolower(trim($label));
        if ($needle !== '' && str_contains($labelLower, $needle)) {
            return 'name contains your search text';
        }
        $matchedTokens = [];
        foreach (self::tokens($needle) as $token) {
            if (str_contains($labelLower, $token)) {
                $matchedTokens[] = $token;
            }
        }
        if ($matchedTokens !== []) {
            return 'matches "'.implode('", "', $matchedTokens).'"';
        }
        similar_text($needle, $labelLower, $pct);
        if ($pct >= 45) {
            return 'similar spelling ('.round($pct).'% match)';
        }

        return 'similar name in Centrix';
    }

    /**
     * @param  list<array<string, mixed>>|null  $screens
     */
    public static function appendScreens(string $message, ?array $screens): string
    {
        if ($screens === null || $screens === []) {
            return $message;
        }
        $links = [];
        foreach (array_slice($screens, 0, 3) as $screen) {
            if (! is_array($screen)) {
                continue;
            }
            $path = trim((string) ($screen['path'] ?? ''));
            if ($path === '') {
                continue;
            }
            $label = trim((string) ($screen['label'] ?? $path));
            $links[] = "[{$label}]({$path})";
        }
        if ($links === []) {
            return $message;
        }

        return $message."\n\nRelated: ".implode(' · ', $links);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function candidateExtra(array $row): string
    {
        $bits = array_filter([
            isset($row['customer_num']) ? '#'.$row['customer_num'] : null,
            isset($row['employee_code']) ? (string) $row['employee_code'] : null,
            isset($row['username']) ? '@'.$row['username'] : null,
            isset($row['supplier_code']) ? (string) $row['supplier_code'] : null,
            isset($row['product_code']) ? (string) $row['product_code'] : null,
        ], fn ($v) => $v !== null && $v !== '');

        return implode(' · ', $bits);
    }
}
