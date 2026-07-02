<?php

namespace Tests\Unit;

use App\Services\Sales\DiscountApprovalService;
use Tests\TestCase;

class DiscountApprovalServiceTest extends TestCase
{
    public function test_discount_percent_calculation(): void
    {
        $service = app(DiscountApprovalService::class);

        $this->assertSame(20.0, $service->discountPercent(200, 1000));
        $this->assertSame(0.0, $service->discountPercent(0, 1000));
        $this->assertSame(0.0, $service->discountPercent(100, 0));
    }

    public function test_threshold_from_settings(): void
    {
        $service = app(DiscountApprovalService::class);

        $this->assertSame(10.0, $service->thresholdPercent(['discount_approval_threshold_percent' => 10]));
        $this->assertSame(100.0, $service->thresholdPercent(['discount_approval_threshold_percent' => 150]));
    }
}
