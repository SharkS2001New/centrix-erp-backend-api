<?php

namespace App\Services\Ai;

/**
 * Centrix AI accepts user questions in English only.
 */
class AiLanguageGuard
{
    /** @var list<string> */
    protected const SWAHILI_PATTERNS = [
        '/\b(nipe|nataka|naweza|tafadhali|fungua|weka|ripoti|idhinisha|ngapi|deni|mauzo|bidhaa|jumla|wateja|wasiliana|karibu|hivi|mwezi|huu|ujao|gani|zipi|nani|amechelewa|haifanyi|haijaingia|sababu|eleza|usibuni|chuja|kadiria|faida|kiasi|dakika|shughuli|zisizo|kawaida|nimwite|tabiri|lipia|malipo|bei|gharama|hesabu|hesabu|kesho|jana|siku|wiki|mwaka)\b/ui',
        '/\b(na|kwa|ya|wa|ni|au|kama|hii|hilo|yake|yangu|yetu|wako|wangu)\s+\w+/ui',
    ];

    /**
     * True when the message should be treated as English (or language-neutral ERP text).
     */
    public function isEnglishQuery(string $message): bool
    {
        $text = trim($message);
        if ($text === '') {
            return true;
        }

        return ! $this->looksLikeSwahili($text);
    }

    public function englishOnlyMessage(): string
    {
        return 'Centrix AI expects questions in **English only**. '
            .'Please ask again in English — for example: "What were yesterday\'s sales?" or "Who owes us money?"';
    }

    protected function looksLikeSwahili(string $text): bool
    {
        $lower = mb_strtolower($text);
        $hits = 0;

        foreach (self::SWAHILI_PATTERNS as $pattern) {
            if (preg_match_all($pattern, $lower, $matches)) {
                $hits += count($matches[0] ?? []);
            }
        }

        // Strong single-word Swahili ERP phrases from shop floor.
        foreach (['nipe mauzo', 'deni ni', 'bidhaa gani', 'nani ame', 'fungua pos', 'vat ya', 'mwezi huu'] as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        return $hits >= 2;
    }
}
