<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use Tests\TestCase;

class CapabilityGateStockDeductionTest extends TestCase
{
    protected function gateWithStockTiming(string $timing): CapabilityGate
    {
        $org = new Organization([
            'module_settings' => [
                'sales' => ['stock_deduct_on' => $timing],
            ],
            'enabled_modules' => ['distribution' => true, 'sales' => true, 'sales.pos' => true],
        ]);

        return (new CapabilityGate($org))->forOrganization($org);
    }

    public function test_order_created_deducts_at_checkout_not_on_workflow_transition(): void
    {
        $gate = $this->gateWithStockTiming('order_created');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'booked', 'mobile'));
        $this->assertFalse($gate->shouldDeductStockOnWorkflowTransition($workflow, 'completed', 'mobile'));
    }

    public function test_order_completed_deducts_only_at_matching_workflow_status(): void
    {
        $gate = $this->gateWithStockTiming('order_completed');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'booked', 'mobile'));
        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'completed', 'pos'));
        $this->assertTrue($gate->shouldDeductStockOnWorkflowTransition($workflow, 'completed', 'backend'));
        $this->assertFalse($gate->shouldDeductStockOnWorkflowTransition($workflow, 'booked', 'backend'));
    }

    public function test_trip_load_defers_checkout_and_workflow_deduction(): void
    {
        $gate = $this->gateWithStockTiming('trip_load');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertTrue($gate->shouldDeferStockToTrip());
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'completed', 'mobile'));
        $this->assertFalse($gate->shouldDeductStockOnWorkflowTransition($workflow, 'completed', 'mobile'));
    }

    public function test_trip_pick_defers_checkout_and_workflow_deduction(): void
    {
        $gate = $this->gateWithStockTiming('trip_pick');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertTrue($gate->shouldDeferStockToTrip());
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'completed', 'mobile'));
        $this->assertFalse($gate->shouldDeductStockOnWorkflowTransition($workflow, 'completed', 'mobile'));
    }

    public function test_held_order_holds_stock_on_checkout(): void
    {
        $gate = $this->gateWithStockTiming('order_completed');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertTrue($gate->shouldHoldStockOnCheckout($workflow, 'held', 'pos'));
        $this->assertFalse($gate->shouldHoldStockOnCheckout($workflow, 'completed', 'pos'));
    }

    public function test_held_order_never_deducts_even_when_timing_is_order_created(): void
    {
        $gate = $this->gateWithStockTiming('order_created');
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'held', 'pos'));
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'draft', 'pos'));
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'pending_approval', 'mobile'));
        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'booked', 'pos'));
        $this->assertTrue($gate->shouldHoldStockOnCheckout($workflow, 'held', 'pos'));
    }

    public function test_without_distribution_mobile_honors_stock_deduct_on_setting(): void
    {
        $org = new Organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => [
                'sales' => true,
                'sales.pos' => true,
                'sales.mobile' => true,
                'sales.backend' => true,
            ],
            'module_settings' => [
                'sales' => [
                    'stock_deduct_on' => [
                        'pos' => 'order_created',
                        'mobile' => 'order_completed',
                        'backend' => 'order_created',
                    ],
                ],
            ],
        ]);
        $gate = (new CapabilityGate($org))->forOrganization($org);
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertFalse($gate->distributionOpsEnabled());
        $this->assertSame('order_completed', $gate->stockDeductTiming('mobile'));
        $this->assertSame('order_created', $gate->stockDeductTiming('backend'));
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'unpaid', 'mobile'));
        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'completed', 'mobile'));
        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'unpaid', 'backend'));
        $this->assertFalse($gate->shouldDeductStockAtCheckout($workflow, 'held', 'mobile'));
    }

    public function test_without_distribution_trip_timing_falls_back_to_order_created(): void
    {
        $org = new Organization([
            'deployment_profile' => 'wholesale_retail',
            'enabled_modules' => ['sales' => true, 'sales.mobile' => true],
            'module_settings' => [
                'sales' => [
                    'stock_deduct_on' => [
                        'mobile' => 'trip_load',
                    ],
                ],
            ],
        ]);
        $gate = (new CapabilityGate($org))->forOrganization($org);
        $workflow = OrderWorkflowService::forGate($gate);

        $this->assertSame('order_created', $gate->stockDeductTiming('mobile'));
        $this->assertFalse($gate->shouldDeferStockToTrip('mobile'));
        $this->assertTrue($gate->shouldDeductStockAtCheckout($workflow, 'unpaid', 'mobile'));
    }
}
