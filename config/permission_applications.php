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
        'mobile',
        'backoffice',
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
        'mobile' => [
            'label' => 'Mobile application',
            'description' => 'Field sales mobile app — orders, customers, stock, and routes.',
            'standalone' => true,
            'registry_modules' => ['mobile'],
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
