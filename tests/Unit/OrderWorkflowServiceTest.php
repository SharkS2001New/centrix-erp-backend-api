<?php

namespace Tests\Unit;

use App\Services\Erp\OrderWorkflowService;
use Tests\TestCase;

class OrderWorkflowServiceTest extends TestCase
{
    public function test_merge_workflow_config_replaces_steps_instead_of_merging_by_index(): void
    {
        $defaults = config('erp.default_order_workflow');
        $custom = [
            'steps' => [
                ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                ['status' => 'completed', 'label' => 'Completed', 'enabled' => true],
            ],
        ];

        $service = OrderWorkflowService::forGate(
            app(\App\Services\Erp\CapabilityGate::class)
        );

        $merged = $service->mergeWorkflowConfig($defaults, $custom);
        $normalized = $service->normalize($merged);

        $statuses = array_column($normalized['steps'], 'status');

        $this->assertSame(['unpaid', 'paid', 'completed'], $statuses);
        $this->assertNotContains('delivered', $statuses);
        $this->assertNotContains('processed', $statuses);
    }
}
