<?php

namespace App\Services\Platform;

/**
 * Allowlist sanitizer for marketing blog HTML bodies.
 */
class MarketingBlogHtml
{
    /** @var list<string> */
    public const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h2', 'h3', 'h4',
        'ul', 'ol', 'li',
        'a', 'img',
        'blockquote', 'pre', 'code',
        'figure', 'figcaption', 'hr', 'span',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Plain-text posts (no tags) stay as-is; website wraps paragraphs.
        if (! preg_match('/<[a-z][\s\S]*>/i', $html)) {
            return $html;
        }

        $allowed = '<'.implode('><', self::ALLOWED_TAGS).'>';
        $clean = strip_tags($html, $allowed);

        // Drop javascript: / data: (except images already extracted) on href/src.
        $clean = preg_replace_callback(
            '/\s(href|src)=([\'"])(.*?)\2/i',
            static function (array $m) {
                $attr = strtolower($m[1]);
                $quote = $m[2];
                $value = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5);
                $lower = strtolower(ltrim($value));
                if (str_starts_with($lower, 'javascript:') || str_starts_with($lower, 'vbscript:')) {
                    return '';
                }
                if ($attr === 'href' && str_starts_with($lower, 'data:')) {
                    return '';
                }
                if ($attr === 'src' && str_starts_with($lower, 'data:') && ! str_starts_with($lower, 'data:image/')) {
                    return '';
                }

                return ' '.$attr.'='.$quote.htmlspecialchars($value, ENT_QUOTES, 'UTF-8').$quote;
            },
            $clean,
        ) ?? $clean;

        // Ensure lazy-loading on images.
        $clean = preg_replace(
            '/<img\b(?![^>]*\bloading=)/i',
            '<img loading="lazy" decoding="async" ',
            $clean,
        ) ?? $clean;

        return trim($clean);
    }

    public static function isHtml(string $body): bool
    {
        return (bool) preg_match('/<[a-z][\s\S]*>/i', $body);
    }

    public static function plainText(string $body): string
    {
        $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    public static function estimateReadMinutes(string $body, int $wpm = 200): int
    {
        $text = self::plainText($body);
        $words = str_word_count($text);
        if ($words < 1 && $text !== '') {
            $words = max(1, (int) ceil(mb_strlen($text) / 5));
        }
        $wpm = max(80, $wpm);

        return max(1, (int) ceil($words / $wpm));
    }
}
