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

        return $this->canonicalizePaths($text);
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
