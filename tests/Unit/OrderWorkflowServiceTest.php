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
        $this->assertSame(['pending_approval'], $service->statusesForQueueFilter('pending_approval', 'backend'));
        $this->assertSame(['editable'], $service->statusesForQueueFilter('editable', 'backend'));
    }

    public function test_cancel_transition_only_allowed_for_early_pipeline_statuses(): void
    {
        $service = OrderWorkflowService::forGate(
            app(\App\Services\Erp\CapabilityGate::class)
        );

        $this->assertTrue($service->canTransition('booked', 'cancelled', 'backend'));
        $this->assertTrue($service->canTransition('pending', 'cancelled', 'backend'));
        $this->assertTrue($service->canTransition('unpaid', 'cancelled', 'backend'));
        $this->assertTrue($service->canTransition('processed', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('pending_payment', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('paid', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('delivered', 'cancelled', 'backend'));
        $this->assertFalse($service->canTransition('completed', 'cancelled', 'backend'));
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

    public function test_resolve_status_after_payment_advances_deferred_fulfillment_to_terminal(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'order_workflow' => [
                        'steps' => [
                            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
                            ['status' => 'pending_payment', 'label' => 'Partially paid', 'enabled' => true],
                            ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
                            ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
                            ['status' => 'delivered', 'label' => 'Delivered', 'enabled' => true],
                            ['status' => 'completed', 'label' => 'Completed', 'enabled' => true],
                        ],
                        'checkout' => [
                            'full_paid' => ['backend' => 'paid'],
                            'partial' => 'pending_payment',
                            'unpaid' => ['backend' => 'unpaid'],
                        ],
                    ],
                ],
            ],
        ]);

        $gate = new \App\Services\Erp\CapabilityGate($org);
        $service = OrderWorkflowService::forGate($gate);

        $this->assertSame('paid', $service->resolveStatusAfterPayment('backend', 'unpaid', 100, 100, false));
        $this->assertSame('pending_payment', $service->resolveStatusAfterPayment('backend', 'unpaid', 50, 100, false));
        $this->assertSame('delivered', $service->resolveStatusAfterPayment('backend', 'delivered', 50, 100, false));
        $this->assertSame('completed', $service->resolveStatusAfterPayment('backend', 'delivered', 100, 100, false));
        $this->assertSame('completed', $service->resolveStatusAfterPayment('backend', 'processed', 100, 100, false));
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

    public function test_order_action_status_helpers_use_defaults_when_unset(): void
    {
        $service = OrderWorkflowService::forGate(
            new \App\Services\Erp\CapabilityGate(new \App\Models\Organization([
                'module_settings' => ['sales' => []],
            ]))
        );

        $this->assertSame(['booked', 'pending', 'editable'], $service->editOrderStatuses());
        $this->assertNull($service->printInvoiceStatuses());
        $this->assertSame(['unpaid', 'pending_payment'], $service->collectPaymentStatuses());
        $this->assertSame(
            ['booked', 'pending', 'unpaid', 'processed', 'pending_approval', 'editable'],
            $service->cancelOrderStatuses(),
        );
        $this->assertSame(
            ['paid', 'processed', 'delivered', 'completed'],
            $service->customerReturnStatuses(),
        );

        $this->assertTrue($service->isEditableLineStatus('booked'));
        $this->assertFalse($service->isEditableLineStatus('paid'));
        $this->assertTrue($service->isPrintInvoiceStatus('paid'));
        $this->assertTrue($service->isCollectPaymentStatus('unpaid'));
        $this->assertFalse($service->isCollectPaymentStatus('paid'));
        $this->assertFalse($service->canCollectPaymentForOrder('booked', 'backend', 'unpaid'));
        $this->assertTrue($service->canCollectPaymentForOrder('unpaid', 'backend', 'unpaid'));
        $this->assertTrue($service->canCollectPaymentForOrder('pending_payment', 'backend', 'partial'));
        $this->assertFalse($service->canCollectPaymentForOrder('booked', 'backend', 'paid'));
        $this->assertTrue($service->isCancellableStatus('booked'));
        $this->assertFalse($service->isCancellableStatus('paid'));
        $this->assertTrue($service->isCustomerReturnStatus('paid'));
        $this->assertFalse($service->isCustomerReturnStatus('unpaid'));
    }

    public function test_order_action_status_helpers_respect_configured_lists(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'edit_order_statuses' => ['unpaid', 'pending_payment'],
                    'print_invoice_statuses' => ['paid', 'completed'],
                    'collect_payment_statuses' => ['delivered'],
                    'cancel_order_statuses' => ['unpaid', 'booked'],
                    'customer_return_statuses' => ['completed'],
                ],
            ],
        ]);

        $service = OrderWorkflowService::forGate(new \App\Services\Erp\CapabilityGate($org));

        $this->assertSame(['unpaid', 'pending_payment'], $service->editOrderStatuses());
        $this->assertSame(['paid', 'completed'], $service->printInvoiceStatuses());
        $this->assertSame(['delivered'], $service->collectPaymentStatuses());

        $this->assertTrue($service->isEditableLineStatus('unpaid'));
        $this->assertFalse($service->isEditableLineStatus('booked'));
        $this->assertTrue($service->isPrintInvoiceStatus('paid'));
        $this->assertFalse($service->isPrintInvoiceStatus('unpaid'));
        $this->assertTrue($service->isCollectPaymentStatus('delivered'));
        $this->assertFalse($service->isCollectPaymentStatus('unpaid'));
        $this->assertFalse($service->isPrintInvoiceStatus('cancelled'));
        $this->assertFalse($service->isCollectPaymentStatus('cancelled'));

        $this->assertSame(['unpaid', 'booked'], $service->cancelOrderStatuses());
        $this->assertTrue($service->isCancellableStatus('unpaid'));
        $this->assertTrue($service->isCancellableStatus('booked'));
        $this->assertFalse($service->isCancellableStatus('processed'));
        $this->assertFalse($service->isCancellableStatus('paid'));

        $this->assertSame(['completed'], $service->customerReturnStatuses());
        $this->assertTrue($service->isCustomerReturnStatus('completed'));
        $this->assertFalse($service->isCustomerReturnStatus('paid'));
        $this->assertFalse($service->isCustomerReturnStatus('cancelled'));
    }

    public function test_mobile_pseudo_stage_allows_actions_on_mobile_channel(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'edit_order_statuses' => ['mobile'],
                    'print_invoice_statuses' => ['mobile'],
                    'collect_payment_statuses' => ['mobile'],
                    'cancel_order_statuses' => ['mobile'],
                    'customer_return_statuses' => ['mobile'],
                    'order_cancellation_enabled' => true,
                ],
            ],
        ]);

        $service = OrderWorkflowService::forGate(new \App\Services\Erp\CapabilityGate($org));

        $this->assertSame(['mobile'], $service->editOrderStatuses());
        $this->assertTrue($service->isEditableLineStatus('booked', 'mobile'));
        $this->assertFalse($service->isEditableLineStatus('booked', 'backend'));
        $this->assertTrue($service->isPrintInvoiceStatus('booked', 'mobile'));
        $this->assertFalse($service->isPrintInvoiceStatus('booked', 'backend'));
        $this->assertTrue($service->isCollectPaymentStatus('booked', 'mobile'));
        $this->assertFalse($service->isCollectPaymentStatus('booked', 'backend'));
        $this->assertTrue($service->isCancellableStatus('booked', 'mobile'));
        $this->assertFalse($service->isCancellableStatus('booked', 'backend'));
        $this->assertTrue($service->isCustomerReturnStatus('processed', 'mobile'));
        $this->assertFalse($service->isCustomerReturnStatus('processed', 'backend'));
    }

    public function test_order_action_flags_respect_platform_stage_config(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'edit_order_statuses' => ['booked', 'pending'],
                    'print_invoice_statuses' => ['paid', 'processed', 'delivered', 'completed'],
                    'collect_payment_statuses' => ['unpaid', 'pending_payment'],
                    'cancel_order_statuses' => ['booked', 'pending'],
                    'customer_return_statuses' => ['processed', 'delivered', 'completed'],
                ],
            ],
        ]);

        $service = OrderWorkflowService::forGate(new \App\Services\Erp\CapabilityGate($org));

        $this->assertTrue($service->isEditableLineStatus('booked'));
        $this->assertFalse($service->isEditableLineStatus('unpaid'));

        $this->assertFalse($service->isPrintInvoiceStatus('booked'));
        $this->assertTrue($service->isPrintInvoiceStatus('paid'));

        $this->assertFalse($service->canCollectPaymentForOrder('booked', 'backend', 'unpaid'));
        $this->assertTrue($service->canCollectPaymentForOrder('unpaid', 'backend', 'unpaid'));
        $this->assertTrue($service->canCollectPaymentForOrder('pending_payment', 'backend', 'partial'));

        $this->assertTrue($service->isCancellableStatus('booked'));
        $this->assertFalse($service->isCancellableStatus('unpaid'));

        $this->assertFalse($service->isCustomerReturnStatus('paid'));
        $this->assertTrue($service->isCustomerReturnStatus('processed'));
    }

    public function test_mobile_order_capability_flags_include_print_and_collect(): void
    {
        $org = new \App\Models\Organization([
            'module_settings' => [
                'sales' => [
                    'edit_order_statuses' => ['booked'],
                    'print_invoice_statuses' => ['paid'],
                    'collect_payment_statuses' => ['unpaid'],
                ],
            ],
        ]);
        $org->id = 1;

        $sale = new \App\Models\Sale([
            'status' => 'unpaid',
            'channel' => 'mobile',
            'organization_id' => 1,
            'created_at' => now(),
        ]);
        $sale->setRelation('organization', $org);

        $user = new \App\Models\User(['organization_id' => 1]);
        $user->setRelation('organization', $org);

        $gate = (new \App\Services\Erp\CapabilityGate($org))->forOrganization($org);
        $flags = app(\App\Services\Sales\MobileSalesService::class)
            ->orderCapabilityFlags($sale, $user, $gate);

        $this->assertArrayHasKey('can_print_invoice', $flags);
        $this->assertArrayHasKey('can_collect_payment', $flags);
        $this->assertFalse($flags['can_print_invoice']);
        $this->assertTrue($flags['can_collect_payment']);

        $sale->status = 'paid';
        $flagsPaid = app(\App\Services\Sales\MobileSalesService::class)
            ->orderCapabilityFlags($sale, $user, $gate);
        $this->assertTrue($flagsPaid['can_print_invoice']);
        $this->assertFalse($flagsPaid['can_collect_payment']);
    }
}
