<?php

/**
 * ERP module registry and deployment profiles.
 *
 * Organizations store deployment_profile + optional enabled_modules overrides.
 * API middleware uses module keys (e.g. sales.pos) to gate routes.
 */
return [

    'profiles' => [
        'small_shop' => [
            'label' => 'Small shop (backend sales only)',
            'modules' => [
                'sales.backend' => true,
                'sales.pos' => false,
                'sales.mobile' => false,
                'payments' => true,
                'inventory' => true,
                'accounting' => false,
                'hr_payroll' => false,
                'admin' => true,
                'customers_suppliers' => true,
                'reports' => true,
            ],
            'default_channels' => ['backend'],
        ],
        'wholesale_retail' => [
            'label' => 'Wholesale & retail (full stack)',
            'modules' => [
                'sales.backend' => true,
                'sales.pos' => true,
                'sales.mobile' => true,
                'payments' => true,
                'inventory' => true,
                'accounting' => true,
                'hr_payroll' => true,
                'admin' => true,
                'customers_suppliers' => true,
                'reports' => true,
            ],
            'default_channels' => ['pos', 'mobile', 'backend'],
        ],
        'distribution' => [
            'label' => 'Distribution / warehouse',
            'modules' => [
                'sales.backend' => true,
                'sales.pos' => false,
                'sales.mobile' => true,
                'payments' => true,
                'inventory' => true,
                'accounting' => true,
                'hr_payroll' => true,
                'admin' => true,
                'customers_suppliers' => true,
                'reports' => true,
            ],
            'default_channels' => ['mobile', 'backend'],
        ],
    ],

    'modules' => [
        'sales.pos' => [
            'label' => 'Point of Sale terminals',
            'routes_prefix' => ['tills', 'till-float-sessions'],
            'channel' => 'pos',
        ],
        'sales.mobile' => [
            'label' => 'Mobile sales app',
            'channel' => 'mobile',
        ],
        'sales.backend' => [
            'label' => 'Backend sales / orders module',
            'channel' => 'backend',
        ],
        'payments' => [
            'label' => 'Payments & credit reconciliation',
        ],
        'inventory' => [
            'label' => 'LPO, receipts, transfers, stock ledger',
        ],
        'accounting' => [
            'label' => 'Accounting (planned)',
        ],
        'hr_payroll' => [
            'label' => 'HR & payroll (planned)',
        ],
        'admin' => [
            'label' => 'Users, roles, permissions',
        ],
        'customers_suppliers' => [
            'label' => 'Customers & suppliers',
        ],
        'reports' => [
            'label' => 'Reporting & analytics',
        ],
    ],

    /*
    | Order lifecycle (fulfillment) — sales.status
    | Payment lifecycle — sales.payment_status + sale_payments + customer_invoices
    */
    'workflows' => [
        'pos' => [
            'statuses' => ['draft', 'held', 'booked', 'unpaid', 'pending_payment', 'paid', 'delivered', 'completed', 'cancelled'],
            'pay_on_complete' => true,
        ],
        'mobile' => [
            'statuses' => ['draft', 'booked', 'pending', 'unpaid', 'pending_payment', 'paid', 'processed', 'delivered', 'completed', 'cancelled'],
            'payment_statuses' => ['unpaid', 'partial', 'paid'],
        ],
        'backend' => [
            'statuses' => ['draft', 'booked', 'pending', 'unpaid', 'pending_payment', 'paid', 'processed', 'delivered', 'completed', 'cancelled'],
            'payment_statuses' => ['unpaid', 'partial', 'paid'],
            'credit_allowed' => true,
        ],
    ],

    'order_status_labels' => [
        'draft' => 'Draft',
        'held' => 'Held',
        'booked' => 'Booked',
        'pending' => 'Pending',
        'unpaid' => 'Unpaid',
        'pending_payment' => 'Partially paid',
        'paid' => 'Paid',
        'processed' => 'Processed',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],

    'default_order_workflow' => [
        'steps' => [
            ['status' => 'booked', 'label' => 'Booked', 'enabled' => true],
            ['status' => 'pending', 'label' => 'Pending', 'enabled' => true],
            ['status' => 'unpaid', 'label' => 'Unpaid', 'enabled' => true],
            ['status' => 'pending_payment', 'label' => 'Partially paid', 'enabled' => true],
            ['status' => 'paid', 'label' => 'Paid', 'enabled' => true],
            ['status' => 'processed', 'label' => 'Processed', 'enabled' => true],
            ['status' => 'delivered', 'label' => 'Delivered', 'enabled' => true],
            ['status' => 'completed', 'label' => 'Completed', 'enabled' => true],
        ],
        'transitions' => [
            'booked' => ['pending', 'unpaid', 'cancelled'],
            'pending' => ['unpaid', 'pending_payment', 'cancelled'],
            'unpaid' => ['pending_payment', 'paid', 'cancelled'],
            'pending_payment' => ['paid', 'cancelled'],
            'paid' => ['processed', 'delivered', 'completed'],
            'processed' => ['delivered', 'completed'],
            'delivered' => ['completed'],
            'draft' => ['held', 'completed', 'booked', 'cancelled'],
            'held' => ['draft', 'booked', 'completed', 'cancelled'],
        ],
        'save_status' => [
            'pos' => 'unpaid',
            'mobile' => 'unpaid',
            'backend' => 'unpaid',
        ],
        'checkout' => [
            'full_paid' => [
                'pos' => 'completed',
                'mobile' => 'paid',
                'backend' => 'paid',
            ],
            'partial' => 'pending_payment',
            'unpaid' => [
                'pos' => 'unpaid',
                'mobile' => 'unpaid',
                'backend' => 'unpaid',
            ],
        ],
        'deduct_stock_on' => 'completed',
    ],

    'module_settings_defaults' => [
        'accounting' => [
            'auto_post_sales' => true,
            'post_till_variance' => true,
        ],
        'sales' => [
            'auto_assign_truck' => true,
            'auto_assign_driver' => true,
            'require_weight_on_load' => false,
            'allow_sell_from_shop' => true,
            'allow_sell_from_store' => false,
            'enable_retail_pricing' => false,
            'allow_discounts' => true,
            'allow_edit_line_discount' => false,
            'enable_order_discount' => false,
            'enable_vouchers' => false,
            'enable_redeemable_points' => false,
            'point_cash_value' => 1,
            'points_earn_per_kes' => 1000,
            'allow_edit_unit_price' => true,
            'enable_barcode_scanner' => false,
            'default_tax_rate' => 16,
            'enable_mpesa_amount' => true,
            'enable_mpesa_code' => true,
            'enable_bank_select' => false,
            'enable_equity_bank' => true,
            'enable_kcb_bank' => true,
            'enable_other_bank' => false,
            'other_bank_name' => 'Other bank',
            'enable_bank_amount' => true,
            'enable_cheque' => true,
            'enable_payment_date' => true,
            'enable_credit_payment' => true,
            'allow_credit_pay_now' => false,
            'show_checkout_on_create_order' => true,
            'enable_checkout_customer_name' => false,
            'retail_shop_wholesale_store_stock' => false,
            'add_route_markup_prices' => false,
            'pos_order_type_mode' => 'normal',
            'enable_mobile_orders' => false,
            'enable_pos_orders' => false,
            'require_pos_till_float' => false,
            'blind_till_close' => false,
            'default_submit_kra' => true,
            'order_document_type' => 'receipt',
            'invoice_valid_days' => 7,
            'order_workflow' => null,
        ],
        'inventory' => [
            'default_receive_location' => 'store',
            'default_pos_sale_location' => 'shop',
            'default_distribution_sale_location' => 'store',
            'reserve_stock_on_cart' => true,
        ],
        'finance' => [
            'enable_kra_device' => false,
            'kra_device_ip' => '',
            'kra_serial_number' => '',
            'kra_pin_number' => '',
            'kra_device_test_mode' => false,
            'kra_plu_register_path' => '/api/upload-plu-data',
            'default_submit_kra' => true,
            'mpesa' => [
                'env' => 'sandbox',
                'consumer_key' => '',
                'consumer_secret' => '',
                'shortcode' => '',
                'till_number' => '',
                'child_storecode' => '',
                'passkey' => '',
                'stk_callback_url' => '',
                'c2b_confirmation_url' => '',
                'c2b_validation_url' => '',
            ],
        ],
    ],
];
