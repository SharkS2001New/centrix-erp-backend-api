<?php

namespace App\Services\Ai;

/**
 * Rough USD cost from token counts using configured list prices.
 */
class AiUsageCostEstimator
{
    public function estimate(string $provider, ?string $model, int $inputTokens, int $outputTokens): float
    {
        $rates = $this->ratesFor($provider, $model);
        $input = max(0, $inputTokens) * ((float) $rates['input'] / 1_000_000);
        $output = max(0, $outputTokens) * ((float) $rates['output'] / 1_000_000);

        return round($input + $output, 6);
    }

    public function usdToKesRate(): float
    {
        $rate = (float) config('ai.pricing.usd_to_kes', 129);

        return $rate > 0 ? $rate : 129.0;
    }

    public function toKes(float $usd): float
    {
        return round($usd * $this->usdToKesRate(), 2);
    }

    /**
     * @return array{
     *   input_rate_per_million: float,
     *   output_rate_per_million: float,
     *   input_usd: float,
     *   output_usd: float,
     *   computed_usd: float
     * }
     */
    public function breakdown(string $provider, ?string $model, int $inputTokens, int $outputTokens): array
    {
        $rates = $this->ratesFor($provider, $model);
        $inputUsd = max(0, $inputTokens) * ((float) $rates['input'] / 1_000_000);
        $outputUsd = max(0, $outputTokens) * ((float) $rates['output'] / 1_000_000);

        return [
            'input_rate_per_million' => (float) $rates['input'],
            'output_rate_per_million' => (float) $rates['output'],
            'input_usd' => round($inputUsd, 6),
            'output_usd' => round($outputUsd, 6),
            'computed_usd' => round($inputUsd + $outputUsd, 6),
        ];
    }

    /**
     * @return array{input: float, output: float}
     */
    public function ratesFor(string $provider, ?string $model): array
    {
        $providerKey = strtolower(trim($provider));
        $block = config("ai.pricing.per_million.{$providerKey}", config('ai.pricing.per_million.openai', []));
        $default = is_array($block['default'] ?? null)
            ? $block['default']
            : ['input' => 0.15, 'output' => 0.60];

        $needle = strtolower(trim((string) $model));
        if ($needle === '') {
            return $this->normalizeRates($default);
        }

        $models = is_array($block['models'] ?? null) ? $block['models'] : [];
        $bestKey = '';
        foreach (array_keys($models) as $key) {
            $candidate = strtolower((string) $key);
            if ($candidate === '') {
                continue;
            }
            if ($needle === $candidate || str_starts_with($needle, $candidate)) {
                if (strlen($candidate) > strlen($bestKey)) {
                    $bestKey = $candidate;
                }
            }
        }

        if ($bestKey !== '' && is_array($models[$bestKey] ?? $models[array_key_first($models)] ?? null)) {
            foreach ($models as $key => $rates) {
                if (strtolower((string) $key) === $bestKey) {
                    return $this->normalizeRates($rates, $default);
                }
            }
        }

        return $this->normalizeRates($default);
    }

    /**
     * @param  mixed  $rates
     * @param  array{input?: float, output?: float}  $fallback
     * @return array{input: float, output: float}
     */
    protected function normalizeRates(mixed $rates, array $fallback = []): array
    {
        $rates = is_array($rates) ? $rates : [];

        return [
            'input' => (float) ($rates['input'] ?? $fallback['input'] ?? 0.15),
            'output' => (float) ($rates['output'] ?? $fallback['output'] ?? 0.60),
        ];
    }
}
