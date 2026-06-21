<?php

/**
 * Groups permission registry modules under tenant applications (workspaces).
 * Used by the roles & permissions UI for parent → child layout.
 */
return [
    'order' => [
        'pos',
        'backoffice',
        'distribution',
        'accounting',
        'hr',
        'admin',
        'mobile',
    ],

    'applications' => [
        'pos' => [
            'label' => 'External POS',
            'description' => 'Cashier terminal, till sessions, and end of day.',
            'registry_modules' => ['pos'],
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
        'distribution' => [
            'label' => 'Distribution',
            'description' => 'Drivers, vehicles, routes, dispatch, and logistics.',
            'registry_modules' => ['fulfillment'],
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
        'admin' => [
            'label' => 'Administration',
            'description' => 'Users, roles, branches, audit trail, and system settings.',
            'registry_modules' => ['admin'],
        ],
        'mobile' => [
            'label' => 'Mobile application',
            'description' => 'Field sales mobile app — separate from web workspaces.',
            'standalone' => true,
            'registry_modules' => ['mobile'],
        ],
    ],
];
