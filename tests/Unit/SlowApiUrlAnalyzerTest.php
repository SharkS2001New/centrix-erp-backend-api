<?php

namespace Tests\Unit;

use App\Services\Platform\SlowApiUrlAnalyzer;
use Tests\TestCase;

class SlowApiUrlAnalyzerTest extends TestCase
{
    public function test_parse_normalizes_full_url_and_method_prefix(): void
    {
        $analyzer = app(SlowApiUrlAnalyzer::class);

        $fromUrl = $analyzer->parseInput(
            'https://erp.example.com/api/v1/reports/daily-sales?from=2026-01-01&to=2026-09-01',
            'GET',
        );
        $this->assertSame('GET', $fromUrl['method']);
        $this->assertSame('/api/v1/reports/daily-sales', $fromUrl['path']);
        $this->assertSame('2026-01-01', $fromUrl['query']['from'] ?? null);

        $fromPrefixed = $analyzer->parseInput('POST /v1/sales/orders');
        $this->assertSame('POST', $fromPrefixed['method']);
        $this->assertSame('/api/v1/sales/orders', $fromPrefixed['path']);

        $bare = $analyzer->parseInput('sales/orders');
        $this->assertSame('/api/v1/sales/orders', $bare['path']);
    }

    public function test_analyze_returns_hypotheses_for_report_url(): void
    {
        $analyzer = app(SlowApiUrlAnalyzer::class);
        $result = $analyzer->analyze(
            'GET /api/v1/reports/daily-sales?from=2026-01-01&to=2026-09-01',
        );

        $this->assertSame('GET', $result['method']);
        $this->assertSame('/api/v1/reports/daily-sales', $result['path']);
        $this->assertNotEmpty($result['hypotheses']);
        $this->assertNotEmpty($result['fast_fix']);
        $this->assertContains('sales', $result['table_hints']);
    }
}
