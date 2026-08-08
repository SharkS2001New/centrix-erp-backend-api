<?php

namespace Tests\Unit;

use App\Services\Pos\TillReportMetrics;
use Illuminate\Database\Query\Builder;
use Mockery;
use Tests\TestCase;

class TillReportMetricsTest extends TestCase
{
    public function test_collected_sales_filter_requires_full_payment(): void
    {
        $metrics = new TillReportMetrics;
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('COALESCE(amount_paid, 0) > ?', [TillReportMetrics::MIN_COLLECTED])
            ->andReturnSelf();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with(
                'COALESCE(amount_paid, 0) + ? >= COALESCE(order_total, 0)',
                [TillReportMetrics::MIN_COLLECTED],
            )
            ->andReturnSelf();

        $metrics->applyCollectedSalesFilter($query);

        $sql = $metrics->collectedSalesSql('s.');
        $this->assertStringContainsString('amount_paid', $sql);
        $this->assertStringContainsString('order_total', $sql);
        $this->assertStringContainsString('>=', $sql);
    }

    public function test_session_tender_filter_allows_credit_partials_not_fake_shortfalls(): void
    {
        $metrics = new TillReportMetrics;
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('whereNotIn')
            ->once()
            ->with('status', ['cancelled', 'expired', 'held', 'draft'])
            ->andReturnSelf();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('COALESCE(amount_paid, 0) > ?', [TillReportMetrics::MIN_COLLECTED])
            ->andReturnSelf();
        $query->shouldReceive('where')
            ->once()
            ->with(Mockery::type('Closure'))
            ->andReturnSelf();

        $metrics->applySessionTenderSalesFilter($query);
    }

    public function test_credit_partial_filter_requires_credit_and_shortfall(): void
    {
        $metrics = new TillReportMetrics;
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('where')
            ->once()
            ->with('is_credit_sale', 1)
            ->andReturnSelf();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with('COALESCE(amount_paid, 0) > ?', [TillReportMetrics::MIN_COLLECTED])
            ->andReturnSelf();
        $query->shouldReceive('whereRaw')
            ->once()
            ->with(
                'COALESCE(amount_paid, 0) + ? < COALESCE(order_total, 0)',
                [TillReportMetrics::MIN_COLLECTED],
            )
            ->andReturnSelf();

        $metrics->applyCreditPartialSalesFilter($query);
    }
}
