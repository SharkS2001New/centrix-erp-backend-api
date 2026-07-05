<?php

namespace Tests\Unit;

use App\Services\Sales\MobileSalesService;
use Carbon\Carbon;
use Tests\TestCase;

class MobileSalesServiceTest extends TestCase
{
    public function test_weekly_buckets_aggregate_daily_rows_without_extra_queries(): void
    {
        $service = new class(
            app(\App\Services\Auth\UserAccessService::class),
            app(\App\Services\Auth\UserMobileOrderScopeService::class),
            app(\App\Services\Erp\ErpContext::class),
            app(\App\Services\Sales\PosOrderEditService::class),
        ) extends MobileSalesService
        {
            public function buckets(array $daily, Carbon $monthStart, Carbon $to): array
            {
                return $this->weeklyBucketsFromDaily($daily, $monthStart, $to);
            }
        };

        $monthStart = Carbon::parse('2026-06-01');
        $to = Carbon::parse('2026-06-10');
        $daily = [
            ['create_date' => '2026-06-01', 'order_count' => 2, 'total_amount' => 100.0],
            ['create_date' => '2026-06-02', 'order_count' => 1, 'total_amount' => 50.0],
            ['create_date' => '2026-06-08', 'order_count' => 3, 'total_amount' => 300.0],
        ];

        $buckets = $service->buckets($daily, $monthStart, $to);

        $this->assertCount(2, $buckets);
        $this->assertSame(3, $buckets[0]['order_count']);
        $this->assertSame(150.0, $buckets[0]['total_amount']);
        $this->assertSame(3, $buckets[1]['order_count']);
        $this->assertSame(300.0, $buckets[1]['total_amount']);
    }
}
