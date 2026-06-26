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

    public function test_is_terminal_status_recognizes_last_pipeline_step(): void
    {
        $service = OrderWorkflowService::forGate(
            app(\App\Services\Erp\CapabilityGate::class)
        );

        $this->assertTrue($service->isTerminalStatus('completed', 'backend'));
        $this->assertFalse($service->isTerminalStatus('paid', 'backend'));
        $this->assertFalse($service->isTerminalStatus('cancelled', 'backend'));
        $this->assertFalse($service->isTerminalStatus('held', 'backend'));
        $this->assertFalse($service->isTerminalStatus('unpaid', 'backend'));
    }

    public function test_custom_payment_only_pipeline_uses_paid_as_terminal(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_workflow' => [
                        'steps' => [
                            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                            ['status' => 'pending_payment', 'label' => 'Partially paid', 'enabled' => true],
                            ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                        ],
                        'checkout' => [
                            'full_paid' => ['pos' => 'completed', 'mobile' => 'paid', 'backend' => 'paid'],
                            'partial' => 'pending_payment',
                            'unpaid' => ['pos' => 'unpaid', 'mobile' => 'unpaid', 'backend' => 'unpaid'],
                        ],
                        'deduct_stock_on' => 'completed',
                    ],
                ],
            ],
        ]);

        $gate = new \App\Services\Erp\CapabilityGate($org);
        $service = OrderWorkflowService::forGate($gate);

        $this->assertSame('paid', $service->lastPipelineStatus('backend'));
        $this->assertTrue($service->isTerminalStatus('paid', 'backend'));
        $this->assertTrue($service->isTerminalStatus('completed', 'backend'));

        $this->assertSame('paid', $service->resolveCheckoutStatus('pos', false, 100, 100, 'CASH'));
        $this->assertSame('pending_payment', $service->resolveCheckoutStatus('pos', false, 50, 100, 'CASH'));
        $this->assertSame('unpaid', $service->resolveCheckoutStatus('backend', false, 0, 100, 'CREDIT', true));
    }

    public function test_align_status_maps_completed_to_paid_in_payment_only_pipeline(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_workflow' => [
                        'steps' => [
                            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                            ['status' => 'pending_payment', 'label' => 'Partially paid', 'enabled' => true],
                            ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                        ],
                    ],
                ],
            ],
        ]);

        $gate = new \App\Services\Erp\CapabilityGate($org);
        $service = OrderWorkflowService::forGate($gate);

        $this->assertSame('paid', $service->alignStatusToPipeline('completed', 'backend'));
        $this->assertSame('unpaid', $service->alignStatusToPipeline('booked', 'backend'));
        $this->assertSame('cancelled', $service->alignStatusToPipeline('cancelled', 'backend'));
        $this->assertContains('completed', $service->statusesForQueueFilter('paid', 'backend'));
    }
}
