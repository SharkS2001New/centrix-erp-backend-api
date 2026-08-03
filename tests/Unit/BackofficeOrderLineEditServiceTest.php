<?php

namespace Tests\Unit;

use App\Models\Sale;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\BackofficeOrderLineEditService;
use App\Services\Sales\DiscountApprovalService;
use App\Services\Sales\PosLinePricingService;
use PHPUnit\Framework\TestCase;

class BackofficeOrderLineEditServiceTest extends TestCase
{
    private function service(): BackofficeOrderLineEditService
    {
        return new BackofficeOrderLineEditService(
            $this->createMock(PosLinePricingService::class),
            $this->createMock(DiscountApprovalService::class),
        );
    }

    public function test_pos_orders_are_eligible_for_backoffice_line_edit(): void
    {
        $gate = $this->createMock(CapabilityGate::class);
        $sale = new Sale([
            'channel' => 'pos',
            'order_source' => 'pos',
            'status' => 'booked',
        ]);

        $this->assertTrue($this->service()->isBackofficeOrder($sale, $gate));
    }

    public function test_mobile_and_backoffice_orders_remain_eligible(): void
    {
        $gate = $this->createMock(CapabilityGate::class);
        $service = $this->service();

        $this->assertTrue($service->isBackofficeOrder(new Sale([
            'channel' => 'mobile',
            'order_source' => 'mobile',
            'status' => 'booked',
        ]), $gate));

        $this->assertTrue($service->isBackofficeOrder(new Sale([
            'channel' => 'backend',
            'order_source' => 'backoffice',
            'status' => 'booked',
            'fulfillment_meta' => ['sales_workspace' => 'backoffice'],
        ]), $gate));
    }
}
