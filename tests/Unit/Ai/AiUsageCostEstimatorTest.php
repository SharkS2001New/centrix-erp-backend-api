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
}
