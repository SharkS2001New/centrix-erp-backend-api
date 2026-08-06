<?php

/**
 * Groups permission registry modules under tenant applications (workspaces).
 * Used by the roles & permissions UI for parent → child layout.
 *
 * Provisioning still uses the six login workspaces in config/erp_applications.php;
 * Mobile application appears here as its own permission group.
 */
return [
    'order' => [
        'pos',
        'hotel_bar_pos',
        'mobile',
        'manager',
        'backoffice',
        'hospitality_backoffice',
        'accounting',
        'hr',
        'distribution',
        'admin',
    ],

    'applications' => [
        'pos' => [
            'label' => 'External POS',
            'description' => 'External POS terminal and checkout.',
            'registry_modules' => ['pos'],
            // Till management / EOD live in Backoffice (sidebar) — not here.
            'module_features' => [
                'pos' => ['terminal', 'checkout'],
            ],
        ],
        'hotel_bar_pos' => [
            'label' => 'Hotel POS',
            'description' => 'Hotel front POS (checks, outlets, room charge) — separate from retail sales.',
            'registry_modules' => ['hotel_bar_pos'],
        ],
        'mobile' => [
            'label' => 'Mobile application',
            'description' => 'Separate permissions for field sales reps and driver delivery users.',
            'standalone' => true,
            'registry_modules' => ['mobile_sales', 'mobile_driver'],
        ],
        'manager' => [
            'label' => 'Manager application',
            'description' => 'Centrix Manager app for approvals, reports, and mobile administration.',
            'standalone' => true,
            'registry_modules' => ['mobile_manager'],
        ],
        'backoffice' => [
            'label' => 'Backoffice',
            'description' => 'Catalog, sales, inventory, procurement, till operations, customers, and operational reports.',
            'registry_modules' => [
                'dashboard',
                'catalogue',
                'pricing_tax',
                'customers',
                'sales',
                'pos',
                'inventory',
                'purchasing',
                'reports',
                'ai',
            ],
            // Only till ops from the pos registry — terminal/checkout stay under External POS.
            // Reports: operational only — finance → Accounting, payroll → HR, logistics → Distribution.
            'module_features' => [
                'pos' => ['till_management', 'end_of_day', 'payments_breakdown'],
                'reports' => [
                    'hub',
                    'builder',
                    'daily_sales',
                    'sales_by_product',
                    'sales_by_supplier',
                    'sales_by_user',
                    'sales_by_customer',
                    'sales_by_channel',
                    'sales_pipeline',
                    'category_sales',
                    'credit_outstanding',
                    'stock_on_hand',
                    'low_stock',
                    'stock_movement',
                    'stock_chain',
                    'stock_valuation',
                    'stock_reservations',
                    'stock_transfers',
                    'branch_stock_transfers',
                    'returns',
                    'price_list',
                    'open_lpo',
                    'purchases_by_supplier',
                    'stock_receipts',
                    'supplier_returns',
                    'damages',
                    'eod_cashier',
                    'eod_report',
                    'till_sessions',
                    'discount_summary',
                    'payment_collection',
                    'vat_collected',
                    'legacy_archive',
                ],
            ],
            'module_labels' => [
                'pos' => 'Till operations',
                'reports' => 'Operational reports',
            ],
        ],
        'hospitality_backoffice' => [
            'label' => 'Hotel Backoffice',
            'description' => 'Rooms, front desk, folios, housekeeping, menu catalogue, LPO, and stock receiving for hotel operations.',
            'registry_modules' => [
                'hospitality',
                'catalogue',
                'pricing_tax',
                'inventory',
                'purchasing',
                'dashboard',
                'reports',
            ],
            'module_features' => [
                'reports' => [
                    'hub',
                    'builder',
                    'stock_on_hand',
                    'low_stock',
                    'stock_movement',
                    'stock_chain',
                    'stock_valuation',
                    'stock_reservations',
                    'stock_transfers',
                    'branch_stock_transfers',
                    'price_list',
                    'open_lpo',
                    'purchases_by_supplier',
                    'stock_receipts',
                    'supplier_returns',
                    'damages',
                ],
            ],
            'module_labels' => [
                'reports' => 'Operational reports',
            ],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'General ledger, journals, receivables, payables, and financial reports.',
            'registry_modules' => ['accounting', 'payments', 'reports'],
            'module_features' => [
                'reports' => [
                    'ar_aging',
                    'top_debtors',
                    'invoice_payments',
                    'customer_statement',
                    'profit_loss',
                    'expenses',
                    'journal_register',
                    'subledger_reconciliation',
                    'kra_receipts',
                    'kra_compliance_summary',
                    'kra_unfiscalized_sales',
                ],
            ],
            'module_labels' => [
                'reports' => 'Financial reports',
            ],
        ],
        'hr' => [
            'label' => 'Human Resources',
            'description' => 'Employees, attendance, leave, payroll, and HR reports.',
            'registry_modules' => ['hr', 'reports'],
            'module_features' => [
                'reports' => [
                    'payroll_summary',
                ],
            ],
            'module_labels' => [
                'reports' => 'HR reports',
            ],
        ],
        'distribution' => [
            'label' => 'Distribution',
            'description' => 'Drivers, vehicles, routes, dispatch, and logistics reports.',
            'registry_modules' => ['fulfillment', 'reports'],
            'module_features' => [
                'reports' => [
                    'mobile_route_sales',
                    'dispatch_trips',
                    'trip_cash_settlement',
                    'pod_compliance',
                    'driver_deliveries',
                ],
            ],
            'module_labels' => [
                'reports' => 'Logistics reports',
            ],
        ],
        'admin' => [
            'label' => 'Administration',
            'description' => 'Users, roles, branches, audit trail, and system settings.',
            'registry_modules' => ['admin'],
        ],
    ],
];
