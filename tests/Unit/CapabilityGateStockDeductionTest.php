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
            'enabled_modules' => ['distribution' => true],
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
}
