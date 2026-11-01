<?php

namespace App\Services\Platform;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Allowlist sanitizer for marketing blog HTML bodies.
 */
class MarketingBlogHtml
{
    /** @var list<string> */
    public const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li',
        'a', 'img',
        'blockquote', 'pre', 'code',
        'figure', 'figcaption', 'hr', 'span',
        'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
    ];

    public static function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        if (! preg_match('/<[a-z][\s\S]*>/i', $html)) {
            return $html;
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            $document->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="marketing-blog-content">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD,
            );
            $root = (new DOMXPath($document))->query('//*[@id="marketing-blog-content"]')?->item(0);
            if (! $root instanceof DOMElement) {
                return '';
            }

            self::sanitizeChildren($root);

            $clean = '';
            foreach ($root->childNodes as $child) {
                $clean .= $document->saveHTML($child);
            }

            return trim($clean);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (! $child instanceof DOMElement) {
                if ($child->nodeType === XML_COMMENT_NODE) {
                    $parent->removeChild($child);
                }

                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                $parent->removeChild($child);

                continue;
            }

            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                self::sanitizeChildren($child);
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);

                continue;
            }

            self::sanitizeAttributes($child, $tag);
            self::sanitizeChildren($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        $allowed = ['style', 'title', 'lang', 'dir'];
        $allowed = match ($tag) {
            'a' => [...$allowed, 'href', 'target', 'rel'],
            'img' => [...$allowed, 'src', 'alt', 'width', 'height', 'loading', 'decoding'],
            'th', 'td' => [...$allowed, 'colspan', 'rowspan', 'scope', 'headers'],
            'col' => [...$allowed, 'span', 'width'],
            'ol' => [...$allowed, 'start', 'reversed'],
            'li' => [...$allowed, 'value'],
            default => $allowed,
        };

        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);
            if (! in_array($name, $allowed, true)) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($name === 'style') {
                $style = self::sanitizeStyle($attribute->value);
                if ($style === '') {
                    $element->removeAttribute('style');
                } else {
                    $element->setAttribute('style', $style);
                }
            } elseif ($name === 'href' || $name === 'src') {
                $isImageSource = $name === 'src' && $tag === 'img';
                if (! self::isSafeUrl($attribute->value, $isImageSource)) {
                    $element->removeAttribute($name);
                }
            } elseif ($name === 'target' && $attribute->value !== '_blank') {
                $element->removeAttribute($name);
            } elseif (in_array($name, ['width', 'height', 'colspan', 'rowspan', 'span', 'start', 'value'], true)
                && ! preg_match('/^\d{1,4}$/', $attribute->value)) {
                $element->removeAttribute($name);
            } elseif ($name === 'dir' && ! in_array(strtolower($attribute->value), ['ltr', 'rtl', 'auto'], true)) {
                $element->removeAttribute($name);
            } elseif ($name === 'scope' && ! in_array(strtolower($attribute->value), ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                $element->removeAttribute($name);
            }
        }

        if ($tag === 'img') {
            $element->setAttribute('loading', 'lazy');
            $element->setAttribute('decoding', 'async');
        }
        if ($tag === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private static function sanitizeStyle(string $style): string
    {
        $allowed = [
            'background-color', 'border', 'border-bottom', 'border-collapse', 'border-color',
            'border-left', 'border-right', 'border-spacing', 'border-style', 'border-top',
            'border-width', 'color', 'font-family', 'font-size', 'font-style', 'font-weight',
            'height', 'margin', 'margin-bottom', 'margin-left', 'margin-right', 'margin-top',
            'max-width', 'min-width', 'padding', 'padding-bottom', 'padding-left', 'padding-right',
            'padding-top', 'text-align', 'text-decoration', 'text-indent', 'vertical-align',
            'white-space', 'width',
        ];
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = explode(':', $declaration, 2);
            $property = strtolower(trim($property));
            $value = trim($value);
            if (! in_array($property, $allowed, true)
                || $value === ''
                || preg_match('/url\s*\(|expression\s*\(|var\s*\(|@import|[<>\\\\]/i', $value)) {
                continue;
            }

            $safeValue = match ($property) {
                'font-size', 'height', 'max-width', 'min-width', 'width', 'margin',
                'margin-bottom', 'margin-left', 'margin-right', 'margin-top',
                'padding', 'padding-bottom', 'padding-left', 'padding-right', 'padding-top',
                'border-spacing', 'border-width', 'border-bottom', 'border-left', 'border-right',
                'border-top', 'text-indent' => (bool) preg_match('/^[#a-zA-Z0-9(),.%\s"\'\/-]+$/', $value),
                'font-family' => (bool) preg_match('/^[a-zA-Z0-9_,\s"\'-]+$/', $value),
                'font-weight' => (bool) preg_match('/^(normal|bold|[1-9]00)$/i', $value),
                'font-style' => in_array(strtolower($value), ['normal', 'italic', 'oblique'], true),
                'text-align' => in_array(strtolower($value), ['left', 'right', 'center', 'justify', 'start', 'end'], true),
                'text-decoration' => (bool) preg_match('/^(none|underline|overline|line-through)(\s+(underline|overline|line-through))*$/i', $value),
                'vertical-align' => in_array(strtolower($value), ['baseline', 'sub', 'super', 'top', 'middle', 'bottom', 'text-top', 'text-bottom'], true),
                'white-space' => in_array(strtolower($value), ['normal', 'nowrap', 'pre', 'pre-wrap', 'pre-line'], true),
                'border-collapse' => in_array(strtolower($value), ['collapse', 'separate'], true),
                'border-color', 'color', 'background-color' => (bool) preg_match('/^(#[0-9a-f]{3,8}|[a-z]{1,24}|rgba?\([\d.,%\s]+\)|hsla?\([\d.,%\s]+\))$/i', $value),
                'border-style' => (bool) preg_match('/^(none|hidden|dotted|dashed|solid|double|groove|ridge|inset|outset)(\s+(none|hidden|dotted|dashed|solid|double|groove|ridge|inset|outset))*$/i', $value),
                default => false,
            };
            if ($safeValue) {
                $safe[] = $property.': '.$value;
            }
        }

        return implode('; ', $safe);
    }

    private static function isSafeUrl(string $url, bool $isImage): bool
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));
        if ($url === '' || preg_match('/[\x00-\x20]/', $url)) {
            return false;
        }
        if ($isImage && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,[a-z0-9+/=\s]+$#i', $url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== '') {
            return $isImage
                ? in_array($scheme, ['http', 'https'], true)
                : in_array($scheme, ['http', 'https', 'mailto', 'tel'], true);
        }

        return (bool) preg_match('!^(?:/|#|[a-z0-9._~$&\'()*+,;=:@%?-])[a-z0-9._~$&\'()*+,;=:@%/?#-]*$!i', $url);
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
