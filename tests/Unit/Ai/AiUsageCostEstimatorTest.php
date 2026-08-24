<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiUsageCostEstimator;
use Tests\TestCase;

class AiUsageCostEstimatorTest extends TestCase
{
    public function test_estimates_gpt4o_mini_from_tokens(): void
    {
        $estimator = app(AiUsageCostEstimator::class);

        // 1M input + 1M output at $0.15 / $0.60 => $0.75
        $cost = $estimator->estimate('openai', 'gpt-4o-mini', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(0.75, $cost, 0.000001);
    }

    public function test_matches_model_prefix(): void
    {
        $estimator = app(AiUsageCostEstimator::class);
        $rates = $estimator->ratesFor('gemini', 'gemini-2.5-flash-preview');

        $this->assertEqualsWithDelta(0.15, $rates['input'], 0.0001);
        $this->assertEqualsWithDelta(0.60, $rates['output'], 0.0001);
    }

    public function test_converts_usd_to_kes_at_configured_rate(): void
    {
        config(['ai.pricing.usd_to_kes' => 129]);
        $estimator = app(AiUsageCostEstimator::class);

        $this->assertEqualsWithDelta(129.0, $estimator->usdToKesRate(), 0.0001);
        $this->assertEqualsWithDelta(12.90, $estimator->toKes(0.10), 0.001);
    }

    public function test_breakdown_splits_input_and_output(): void
    {
        $estimator = app(AiUsageCostEstimator::class);
        $detail = $estimator->breakdown('openai', 'gpt-4o-mini', 1_000_000, 500_000);

        $this->assertEqualsWithDelta(0.15, $detail['input_usd'], 0.000001);
        $this->assertEqualsWithDelta(0.30, $detail['output_usd'], 0.000001);
        $this->assertEqualsWithDelta(0.45, $detail['computed_usd'], 0.000001);
    }
}
