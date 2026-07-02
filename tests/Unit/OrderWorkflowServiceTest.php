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

    public function test_statuses_for_queue_filter_includes_expired(): void
    {
        $service = OrderWorkflowService::forGate(
            app(\App\Services\Erp\CapabilityGate::class)
        );

        $this->assertSame(['expired'], $service->statusesForQueueFilter('expired', 'backend'));
        $this->assertSame(['cancelled'], $service->statusesForQueueFilter('cancelled', 'backend'));
    }

    public function test_cancel_transition_only_allowed_for_early_pipeline_statuses(): void
    {
        $service = OrderWorkflowService::forGate(
            app(\App\Services\Erp\CapabilityGate::class)
        );

        $this->assertTrue($service->canTransition('booked', 'cancelled', 'backend'));
        $this->assertTrue($service->canTransition('pending', 'cancelled', 'backend'));
        $this->assertTrue($service->canTransition('unpaid', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('pending_payment', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('paid', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('processed', 'cancelled', 'backend'));
    }

    public function test_cancel_transition_respects_disabled_setting(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_cancellation_enabled' => false,
                ],
            ],
        ]);

        $service = OrderWorkflowService::forGate(new \App\Services\Erp\CapabilityGate($org));

        $this->assertFalse($service->canTransition('booked', 'cancelled', 'backend'));
    }

    public function test_should_have_stock_reserved_when_at_or_past_reserve_status(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_workflow' => [
                        'steps' => [
                            ['status' => 'booked', 'label' => 'Booked', 'enabled' => true],
                            ['status' => 'pending', 'label' => 'Pending', 'enabled' => true],
                            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                            ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
                        ],
                        'save_status' => ['backend' => 'unpaid'],
                        'reserve_stock_on' => ['backend' => 'booked'],
                        'deduct_stock_on' => ['backend' => 'processed'],
                    ],
                    'stock_deduct_on' => ['backend' => 'trip_load'],
                ],
            ],
            'enabled_modules' => ['distribution' => true, 'sales.backend' => true],
        ]);

        $gate = new \App\Services\Erp\CapabilityGate($org);
        $service = OrderWorkflowService::forGate($gate);

        $this->assertFalse($service->shouldReserveStockOn('unpaid', 'backend'));
        $this->assertTrue($service->shouldHaveStockReserved('booked', 'backend'));
        $this->assertTrue($service->shouldHaveStockReserved('unpaid', 'backend'));
        $this->assertTrue($service->shouldHaveStockReserved('processed', 'backend'));
        $this->assertTrue($gate->shouldReserveStockOnCheckout($service, 'unpaid', 'backend'));
        $this->assertTrue($gate->shouldReserveStockOnTransition($service, 'booked', 'backend'));
        $this->assertFalse($gate->shouldReserveStockOnTransition($service, 'pending', 'backend'));
        $this->assertFalse($gate->shouldReserveStockOnTransition($service, 'unpaid', 'backend'));
    }

    public function test_restorable_to_cart_statuses_follow_org_terminal_and_checkout_re_edit(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_workflow' => [
                        'steps' => [
                            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                            ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                        ],
                        'checkout' => [
                            'full_paid' => ['pos' => 'paid', 'mobile' => 'paid', 'backend' => 'paid'],
                        ],
                    ],
                ],
            ],
        ]);

        $service = OrderWorkflowService::forGate((new \App\Services\Erp\CapabilityGate($org))->forOrganization($org));

        $withoutReEdit = $service->restorableToCartStatuses('pos', false);
        $withReEdit = $service->restorableToCartStatuses('pos', true);

        $this->assertContains('unpaid', $withoutReEdit);
        $this->assertNotContains('paid', $withoutReEdit);
        $this->assertContains('paid', $withReEdit);
        $this->assertTrue($service->isRestorableToCartStatus('paid', 'pos', true));
        $this->assertFalse($service->isRestorableToCartStatus('paid', 'pos', false));
    }
}
