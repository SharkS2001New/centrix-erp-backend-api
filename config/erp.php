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
            'statuses' => ['draft', 'held', 'completed', 'cancelled'],
            'pay_on_complete' => true,
        ],
        'mobile' => [
            'statuses' => ['draft', 'booked', 'pending', 'pending_payment', 'paid', 'processed', 'completed', 'cancelled'],
            'payment_statuses' => ['unpaid', 'partial', 'paid'],
        ],
        'backend' => [
            'statuses' => ['draft', 'booked', 'pending', 'pending_payment', 'paid', 'processed', 'completed', 'cancelled'],
            'payment_statuses' => ['unpaid', 'partial', 'paid'],
            'credit_allowed' => true,
        ],
    ],

    'module_settings_defaults' => [
        'accounting' => [
            'auto_post_sales' => true,
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
        ],
        'inventory' => [
            'default_receive_location' => 'store',
            'default_pos_sale_location' => 'shop',
            'default_distribution_sale_location' => 'store',
            'reserve_stock_on_cart' => true,
        ],
    ],
];
