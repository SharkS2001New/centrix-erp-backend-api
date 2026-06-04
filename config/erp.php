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
        ],
        'inventory' => [
            'default_receive_location' => 'store',
            'default_pos_sale_location' => 'shop',
            'default_distribution_sale_location' => 'store',
            'reserve_stock_on_cart' => true,
        ],
    ],
];
