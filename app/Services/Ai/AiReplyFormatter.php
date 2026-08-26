<?php

namespace App\Services\Ai;

/**
 * Post-process assistant text: plain-language formulas, real Centrix paths only.
 */
class AiReplyFormatter
{
    /**
     * Prefixes that are valid even when the full path is not in the nav map
     * (detail screens, catalog reports, saved custom reports).
     *
     * @var list<string>
     */
    protected const ALLOWED_PREFIXES = [
        '/reports/custom/',
        '/reports/',
        '/sales/orders/',
        '/hr/employees/',
        '/products/',
        '/customers/',
        '/lpo/',
        '/inventory/',
        '/accounting/',
        '/admin/',
        '/fulfillment/',
        '/hospitality/',
        '/suppliers/',
        '/sales/',
        '/hr/',
        '/pos',
        '/dashboard',
        '/categories',
        '/expenses',
        '/uoms',
        '/vats',
        '/price-history',
    ];

    public function format(string $text): string
    {
        $text = $this->latexToPlain($text);
        $text = $this->stripRedundantIdentityColumns($text);

        return $this->canonicalizePaths($text);
    }

    /**
     * Drop Code / SKU / Id columns from markdown tables when a name column is present.
     * Centrix AI shows product/customer/supplier/employee names only.
     */
    public function stripRedundantIdentityColumns(string $text): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $text);
        if ($lines === false || count($lines) < 2) {
            return $text;
        }

        $out = [];
        $i = 0;
        $n = count($lines);

        while ($i < $n) {
            $headerCells = $this->splitMarkdownRow($lines[$i]);
            $nextIsSeparator = ($i + 1) < $n && $this->isMarkdownSeparatorRow($lines[$i + 1]);

            if ($headerCells === [] || ! $nextIsSeparator) {
                $out[] = $lines[$i];
                $i++;

                continue;
            }

            $dropIndexes = $this->identityColumnIndexesToDrop($headerCells);
            if ($dropIndexes === []) {
                $out[] = $lines[$i];
                $i++;

                continue;
            }

            // Header + separator + following data rows.
            while ($i < $n) {
                $cells = $this->splitMarkdownRow($lines[$i]);
                if ($cells === [] && trim($lines[$i]) !== '') {
                    break;
                }
                if ($cells === []) {
                    $out[] = $lines[$i];
                    $i++;
                    break;
                }
                if (count($cells) !== count($headerCells) && ! $this->isMarkdownSeparatorRow($lines[$i])) {
                    // Different table / prose — stop.
                    if ($i > 0 && isset($out[count($out) - 1]) === false) {
                        // no-op
                    }
                    break;
                }

                $kept = [];
                foreach ($cells as $col => $cell) {
                    if (! isset($dropIndexes[$col])) {
                        $kept[] = $cell;
                    }
                }
                if ($kept !== []) {
                    $out[] = '| '.implode(' | ', $kept).' |';
                }
                $i++;

                // After separator, keep consuming rows that look like table rows with same width.
                if ($i < $n && ! $this->isMarkdownSeparatorRow($lines[$i - 1])) {
                    // already advanced; continue while next line is a same-width table row
                }
                if ($i >= $n) {
                    break;
                }
                $peek = $this->splitMarkdownRow($lines[$i]);
                if ($peek === [] || (count($peek) !== count($headerCells) && ! $this->isMarkdownSeparatorRow($lines[$i]))) {
                    break;
                }
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param  list<string>  $headerCells
     * @return array<int, true>
     */
    protected function identityColumnIndexesToDrop(array $headerCells): array
    {
        $hasNameColumn = false;
        foreach ($headerCells as $header) {
            $h = mb_strtolower(trim($header));
            if ($h === '' || $h === '#') {
                continue;
            }
            if (preg_match('/\b(product|customer|supplier|employee|cashier|user|name)\b/u', $h)
                && ! preg_match('/\b(code|sku|id|num|number)\b/u', $h)) {
                $hasNameColumn = true;
                break;
            }
        }
        if (! $hasNameColumn) {
            return [];
        }

        $dropIndexes = [];
        foreach ($headerCells as $i => $header) {
            $h = mb_strtolower(trim($header));
            if ($h === '') {
                continue;
            }
            if (preg_match('/^(code|sku|product\s*code|item\s*code|barcode)$/u', $h)
                || preg_match('/^(id|user\s*id|employee\s*id|employee\s*code|supplier\s*id|supplier\s*code|customer\s*(#|num|number|id))$/u', $h)
                || preg_match('/\b(product\s*code|sku)\b/u', $h)) {
                $dropIndexes[$i] = true;
            }
        }

        return $dropIndexes;
    }

    protected function isMarkdownSeparatorRow(string $line): bool
    {
        $line = trim($line);
        if ($line === '' || ! str_contains($line, '|')) {
            return false;
        }

        return (bool) preg_match('/^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)+\|?\s*$/u', $line);
    }

    /** @return list<string> */
    protected function splitMarkdownRow(string $line): array
    {
        $line = trim($line);
        if ($line === '' || ! str_contains($line, '|')) {
            return [];
        }
        if ($this->isMarkdownSeparatorRow($line)) {
            $line = trim($line, '|');
            $parts = explode('|', $line);

            return array_map(static fn ($p) => trim((string) $p), $parts);
        }
        $line = trim($line, '|');
        $parts = explode('|', $line);

        return array_map(static fn ($p) => trim((string) $p), $parts);
    }

    public function latexToPlain(string $text): string
    {
        $text = preg_replace_callback('/\$\$([\s\S]+?)\$\$/', function (array $m) {
            return $this->convertLatexFragment((string) ($m[1] ?? ''));
        }, $text) ?? $text;

        $text = preg_replace_callback('/(?<!\$)\$([^$\n]+)\$(?!\$)/', function (array $m) {
            return $this->convertLatexFragment((string) ($m[1] ?? ''));
        }, $text) ?? $text;

        return $this->convertLatexFragment($text);
    }

    public function canonicalizePaths(string $text): string
    {
        $aliases = config('ai_navigation.path_aliases', []);
        $known = $this->knownExactPaths();

        return preg_replace_callback(
            '/(?<![A-Za-z0-9])(\/[a-z][\w\-\/]*)/',
            function (array $m) use ($aliases, $known) {
                $path = rtrim((string) $m[1], '/');
                if ($path === '') {
                    return (string) $m[1];
                }
                if (isset($aliases[$path])) {
                    return $aliases[$path];
                }
                if (isset($known[$path]) || $this->hasAllowedPrefix($path)) {
                    return $path === (string) $m[1] ? (string) $m[1] : $path;
                }

                $closest = $this->closestKnownPath($path, $known);
                if ($closest !== null) {
                    return $closest;
                }

                return (string) $m[1];
            },
            $text
        ) ?? $text;
    }

    protected function convertLatexFragment(string $inner): string
    {
        $out = $inner;
        $out = preg_replace('/\\\\text\{([^}]+)\}/', '$1', $out) ?? $out;
        $out = preg_replace('/\\\\mathrm\{([^}]+)\}/', '$1', $out) ?? $out;
        $out = preg_replace('/\\\\textbf\{([^}]+)\}/', '$1', $out) ?? $out;
        $out = str_replace(
            ['\\times', '\\cdot', '\\div', '\\leq', '\\geq', '\\neq', '\\approx'],
            ['×', '·', '÷', '≤', '≥', '≠', '≈'],
            $out,
        );
        $out = preg_replace('/\\\\frac\{([^}]+)\}\{([^}]+)\}/', '$1 / $2', $out) ?? $out;
        $out = str_replace(['{', '}', '\\left', '\\right', '\\,', '\\;', '\\!', '\\\\'], ['', '', '', '', ' ', ' ', '', ' '], $out);
        $out = preg_replace('/\\\\[a-zA-Z]+/', '', $out) ?? $out;

        return trim(preg_replace('/[ \t]+/', ' ', $out) ?? $out);
    }

    /**
     * @return array<string, true>
     */
    protected function knownExactPaths(): array
    {
        $paths = [];
        foreach (config('ai_navigation.sections', []) as $section) {
            foreach ($section['items'] ?? [] as $item) {
                $path = rtrim((string) ($item['path'] ?? ''), '/');
                if ($path !== '' && str_starts_with($path, '/')) {
                    $paths[$path] = true;
                }
            }
        }
        foreach (config('ai_knowledge.modules', []) as $module) {
            foreach ($module['paths'] ?? [] as $path) {
                $path = rtrim((string) $path, '/');
                if ($path !== '' && str_starts_with($path, '/')) {
                    $paths[$path] = true;
                }
            }
        }
        foreach (config('ai_knowledge.workflows', []) as $workflow) {
            $path = rtrim((string) ($workflow['path'] ?? ''), '/');
            if ($path !== '' && str_starts_with($path, '/')) {
                $paths[$path] = true;
            }
        }
        foreach (config('ai_navigation.path_aliases', []) as $canonical) {
            $path = rtrim((string) $canonical, '/');
            if ($path !== '') {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    protected function hasAllowedPrefix(string $path): bool
    {
        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if ($path === rtrim($prefix, '/') || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $known
     */
    protected function closestKnownPath(string $path, array $known): ?string
    {
        $needle = strtolower(trim(str_replace(['/', '-'], ' ', $path)));
        $best = null;
        $bestScore = 0;
        foreach (array_keys($known) as $candidate) {
            $hay = strtolower(trim(str_replace(['/', '-'], ' ', $candidate)));
            similar_text($needle, $hay, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $candidate;
            }
        }

        return $bestScore >= 72 ? $best : null;
    }
}
