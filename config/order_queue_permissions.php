<?php

/**
 * Sales order queue sidebar permissions (sales.order_queue_{slug}.view).
 * Pipeline rows appear in the roles matrix only when enabled in the org order workflow.
 */
return [
    'all' => [
        'label' => 'All orders',
        'always' => true,
    ],
    'booked' => ['label' => 'Booked', 'pipeline' => true],
    'pending' => ['label' => 'Pending', 'pipeline' => true],
    'unpaid' => ['label' => 'Unpaid', 'pipeline' => true],
    'pending_payment' => ['label' => 'Partially paid', 'pipeline' => true],
    'paid' => ['label' => 'Paid', 'pipeline' => true],
    'processed' => ['label' => 'Processed', 'pipeline' => true],
    'delivered' => ['label' => 'Delivered', 'pipeline' => true],
    'completed' => ['label' => 'Completed', 'pipeline' => true],
    'cancelled' => [
        'label' => 'Cancelled',
        'terminal' => true,
        'requires_setting' => 'order_cancellation_enabled',
    ],
    'expired' => [
        'label' => 'Expired',
        'terminal' => true,
        'requires_setting' => 'order_expiry_enabled',
    ],
    'pending_approval' => [
        'label' => 'Pending approval',
        'terminal' => true,
        'requires_setting' => 'discount_approval_enabled',
    ],
    'editable' => [
        'label' => 'Editable',
        'terminal' => true,
        'requires_setting' => 'discount_approval_enabled',
    ],
    'mobile' => [
        'label' => 'Mobile orders',
        'mobile_channel' => true,
    ],
];
