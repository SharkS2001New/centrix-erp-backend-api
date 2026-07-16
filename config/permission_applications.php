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
            'label' => 'External ERP',
            'description' => 'External POS terminal, till sessions, and end of day.',
            'registry_modules' => ['pos'],
        ],
        'hotel_bar_pos' => [
            'label' => 'Hotel & Bar POS',
            'description' => 'Hospitality front POS (checks, outlets, room charge) — separate from retail sales.',
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
            'description' => 'Catalog, sales, inventory, procurement, customers, and operational reports.',
            'registry_modules' => [
                'dashboard',
                'catalogue',
                'customers',
                'sales',
                'inventory',
                'purchasing',
                'reports',
                'ai',
            ],
        ],
        'hospitality_backoffice' => [
            'label' => 'Hospitality Backoffice',
            'description' => 'Rooms, front desk, folios, housekeeping, and hotel operations.',
            'registry_modules' => ['hospitality'],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'General ledger, journals, receivables, payables, and financial reports.',
            'registry_modules' => ['accounting', 'payments'],
        ],
        'hr' => [
            'label' => 'Human Resources',
            'description' => 'Employees, attendance, leave, and payroll.',
            'registry_modules' => ['hr'],
        ],
        'distribution' => [
            'label' => 'Distribution',
            'description' => 'Drivers, vehicles, routes, dispatch, and logistics.',
            'registry_modules' => ['fulfillment'],
        ],
        'admin' => [
            'label' => 'Administration',
            'description' => 'Users, roles, branches, audit trail, and system settings.',
            'registry_modules' => ['admin'],
        ],
    ],
];
