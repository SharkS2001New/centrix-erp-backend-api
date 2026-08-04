<?php

namespace App\Services\Sales;

use Illuminate\Validation\ValidationException;

/**
 * Safe arithmetic evaluator for org markup formulas.
 * Supports + - * / parentheses and numeric placeholders already substituted.
 */
class PricingFormulaEvaluator
{
    /**
     * @param  array<string, float|int|string>  $vars
     */
    public static function evaluate(string $formula, array $vars, ?float $fallback = null): float
    {
        $expression = self::substitute($formula, $vars);
        try {
            $value = self::evaluateExpression($expression);
        } catch (\Throwable $e) {
            if ($fallback !== null) {
                return round($fallback, 2);
            }
            throw ValidationException::withMessages([
                'pricing_formula' => ['Invalid pricing formula: '.$e->getMessage()],
            ]);
        }

        if (! is_finite($value)) {
            if ($fallback !== null) {
                return round($fallback, 2);
            }
            throw ValidationException::withMessages([
                'pricing_formula' => ['Pricing formula produced a non-finite result.'],
            ]);
        }

        return round($value, 2);
    }

    /**
     * @param  array<string, float|int|string>  $vars
     */
    public static function substitute(string $formula, array $vars): string
    {
        $out = trim($formula);
        if ($out === '') {
            throw new \InvalidArgumentException('Formula is empty.');
        }

        uksort($vars, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));
        foreach ($vars as $key => $value) {
            $name = trim((string) $key);
            if ($name === '' || ! preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
                continue;
            }
            $num = is_numeric($value) ? (float) $value : 0.0;
            $out = str_ireplace('{'.$name.'}', self::formatNumber($num), $out);
        }

        if (preg_match('/\{[a-z][a-z0-9_]*\}/i', $out)) {
            throw new \InvalidArgumentException('Unknown placeholder in formula.');
        }

        return $out;
    }

    public static function validateSyntax(string $formula, array $allowedPlaceholders): void
    {
        $trimmed = trim($formula);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'pricing_formula' => ['Formula cannot be empty.'],
            ]);
        }

        if (! preg_match('/^[0-9+\-*\/().\s{}\w]+$/', $trimmed)) {
            throw ValidationException::withMessages([
                'pricing_formula' => ['Formula may only use numbers, + − × ÷, parentheses, and placeholders.'],
            ]);
        }

        preg_match_all('/\{([a-z][a-z0-9_]*)\}/i', $trimmed, $matches);
        $allowed = array_fill_keys(array_map('strtolower', $allowedPlaceholders), true);
        foreach ($matches[1] ?? [] as $name) {
            if (! isset($allowed[strtolower($name)])) {
                throw ValidationException::withMessages([
                    'pricing_formula' => ["Unknown placeholder {{$name}}."],
                ]);
            }
        }

        $sample = [];
        foreach ($allowedPlaceholders as $name) {
            $sample[$name] = 1.0;
        }
        self::evaluate($trimmed, $sample, null);
    }

    protected static function formatNumber(float $num): string
    {
        if (! is_finite($num)) {
            return '0';
        }

        return rtrim(rtrim(sprintf('%.8F', $num), '0'), '.') ?: '0';
    }

    protected static function evaluateExpression(string $expression): float
    {
        $normalized = preg_replace('/\s+/', '', $expression) ?? '';
        if ($normalized === '' || ! preg_match('/^[0-9+\-*\/().]+$/', $normalized)) {
            throw new \InvalidArgumentException('Expression contains invalid characters.');
        }

        $tokens = self::tokenize($normalized);
        $rpn = self::toRpn($tokens);

        return self::evalRpn($rpn);
    }

    /** @return list<string> */
    protected static function tokenize(string $expression): array
    {
        $tokens = [];
        $length = strlen($expression);
        $i = 0;
        while ($i < $length) {
            $ch = $expression[$i];
            if ($ch === '+' || $ch === '*' || $ch === '/' || $ch === '(' || $ch === ')') {
                $tokens[] = $ch;
                $i++;
                continue;
            }
            if ($ch === '-') {
                $prev = $tokens === [] ? null : $tokens[count($tokens) - 1];
                $unary = $prev === null || $prev === '(' || in_array($prev, ['+', '-', '*', '/'], true);
                if ($unary) {
                    $i++;
                    if ($i >= $length || ! ctype_digit($expression[$i]) && $expression[$i] !== '.') {
                        throw new \InvalidArgumentException('Invalid unary minus.');
                    }
                    $start = $i;
                    while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                        $i++;
                    }
                    $tokens[] = '-'.substr($expression, $start, $i - $start);
                    continue;
                }
                $tokens[] = '-';
                $i++;
                continue;
            }
            if (ctype_digit($ch) || $ch === '.') {
                $start = $i;
                while ($i < $length && (ctype_digit($expression[$i]) || $expression[$i] === '.')) {
                    $i++;
                }
                $tokens[] = substr($expression, $start, $i - $start);
                continue;
            }
            throw new \InvalidArgumentException('Unexpected character in formula.');
        }

        return $tokens;
    }

    /**
     * @param  list<string>  $tokens
     * @return list<string>
     */
    protected static function toRpn(array $tokens): array
    {
        $output = [];
        $stack = [];
        $precedence = ['+' => 1, '-' => 1, '*' => 2, '/' => 2];

        foreach ($tokens as $token) {
            if (is_numeric($token)) {
                $output[] = $token;
                continue;
            }
            if ($token === '(') {
                $stack[] = $token;
                continue;
            }
            if ($token === ')') {
                while ($stack !== [] && end($stack) !== '(') {
                    $output[] = array_pop($stack);
                }
                if ($stack === [] || array_pop($stack) !== '(') {
                    throw new \InvalidArgumentException('Mismatched parentheses.');
                }
                continue;
            }
            if (! isset($precedence[$token])) {
                throw new \InvalidArgumentException('Unknown operator.');
            }
            while (
                $stack !== []
                && isset($precedence[end($stack)])
                && $precedence[end($stack)] >= $precedence[$token]
            ) {
                $output[] = array_pop($stack);
            }
            $stack[] = $token;
        }

        while ($stack !== []) {
            $op = array_pop($stack);
            if ($op === '(' || $op === ')') {
                throw new \InvalidArgumentException('Mismatched parentheses.');
            }
            $output[] = $op;
        }

        return $output;
    }

    /**
     * @param  list<string>  $rpn
     */
    protected static function evalRpn(array $rpn): float
    {
        $stack = [];
        foreach ($rpn as $token) {
            if (is_numeric($token)) {
                $stack[] = (float) $token;
                continue;
            }
            if (count($stack) < 2) {
                throw new \InvalidArgumentException('Invalid expression.');
            }
            $b = array_pop($stack);
            $a = array_pop($stack);
            $stack[] = match ($token) {
                '+' => $a + $b,
                '-' => $a - $b,
                '*' => $a * $b,
                '/' => abs($b) < 1e-12 ? throw new \InvalidArgumentException('Division by zero.') : $a / $b,
                default => throw new \InvalidArgumentException('Unknown operator.'),
            };
        }

        if (count($stack) !== 1) {
            throw new \InvalidArgumentException('Invalid expression.');
        }

        return (float) $stack[0];
    }
}
