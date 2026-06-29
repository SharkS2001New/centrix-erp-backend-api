<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Erp\OrderWorkflowService;
use App\Services\Sales\PosOrderEditService;
use Tests\TestCase;

class PosOrderEditServiceTest extends TestCase
{
    protected function gateWithPosOrderEdit(bool $enabled, ?array $orderWorkflow = null): CapabilityGate
    {
        $sales = ['enable_pos_order_edit' => $enabled];
        if ($orderWorkflow !== null) {
            $sales['order_workflow'] = $orderWorkflow;
        }

        $org = new Organization([
            'module_settings' => ['sales' => $sales],
            'enabled_modules' => ['sales.pos' => true, 'sales.mobile' => true],
        ]);

        return (new CapabilityGate($org))->forOrganization($org);
    }

    public function test_completed_pos_orders_are_editable_when_pos_order_edit_enabled(): void
    {
        $service = new PosOrderEditService(app(\App\Services\Sales\CustomerReturnService::class));
        $gate = $this->gateWithPosOrderEdit(true);

        $editable = $service->editableStatusesForChannel('pos', $gate);

        $this->assertContains('completed', $editable);
        $this->assertNotContains('cancelled', $editable);
    }

    public function test_custom_pos_workflow_uses_org_checkout_status_for_re_edit(): void
    {
        $service = new PosOrderEditService(app(\App\Services\Sales\CustomerReturnService::class));
        $gate = $this->gateWithPosOrderEdit(true, [
            'steps' => [
                ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
            ],
            'checkout' => [
                'full_paid' => ['pos' => 'paid', 'mobile' => 'paid', 'backend' => 'paid'],
            ],
        ]);

        $editable = $service->editableStatusesForChannel('pos', $gate);

        $this->assertContains('paid', $editable);
        $this->assertNotContains('completed', $editable);
    }

    public function test_completed_pos_orders_are_not_editable_when_pos_order_edit_disabled(): void
    {
        $service = new PosOrderEditService(app(\App\Services\Sales\CustomerReturnService::class));
        $gate = $this->gateWithPosOrderEdit(false);

        $editable = $service->editableStatusesForChannel('pos', $gate);

        $this->assertContains('held', $editable);
        $this->assertNotContains('completed', $editable);
    }

    public function test_backend_orders_follow_org_workflow_before_terminal(): void
    {
        $gate = $this->gateWithPosOrderEdit(false, [
            'steps' => [
                ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                ['status' => 'completed', 'label' => 'Completed', 'enabled' => true],
            ],
        ]);
        $workflow = OrderWorkflowService::forGate($gate);

        $restorable = $workflow->restorableToCartStatuses('backend', false);

        $this->assertContains('unpaid', $restorable);
        $this->assertContains('held', $restorable);
        $this->assertNotContains('completed', $restorable);
    }

    public function test_cashier_cannot_re_edit_another_cashiers_order(): void
    {
        $service = new PosOrderEditService(app(\App\Services\Sales\CustomerReturnService::class));
        $gate = $this->gateWithPosOrderEdit(true);

        $owner = new \App\Models\User(['is_admin' => false]);
        $owner->id = 10;
        $otherCashier = new \App\Models\User(['is_admin' => false]);
        $otherCashier->id = 20;

        $sale = new \App\Models\Sale([
            'status' => 'completed',
            'archived' => 0,
            'channel' => 'pos',
            'cashier_id' => $owner->id,
        ]);

        try {
            $service->assertSaleEditable($sale, $otherCashier, $gate);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('You can only re-edit your own orders.', $e->getMessage());
        }
    }
}
