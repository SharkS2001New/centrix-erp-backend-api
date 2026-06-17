<?php

/**
 * Product workspaces — same login, different shells after sign-in.
 *
 * `domain_modules` / `module_keys` gate org-level module toggles.
 * `permission_prefixes` gate user-level access (cashiers with only pos.* see POS only).
 */
return [
    'pos' => [
        'label' => 'External POS',
        'description' => 'Cashier checkout terminal, till sessions, and end of day.',
        'icon' => 'pos',
        'home_path' => '/pos',
        'module_keys' => ['sales.pos'],
        'permission_prefixes' => ['pos.'],
        'entry_permission' => 'pos.terminal.view',
    ],
    'backoffice' => [
        'label' => 'Backoffice',
        'description' => 'Sales, inventory, purchasing, logistics, and day-to-day operations.',
        'icon' => 'building',
        'home_path' => '/dashboard',
        'domain_modules' => ['sales', 'inventory', 'customers_suppliers', 'distribution'],
        'permission_prefixes' => [
            'catalogue.',
            'customers.',
            'sales.',
            'inventory.',
            'purchasing.',
            'fulfillment.',
            'reports.',
        ],
        'home_path_by_permissions' => [
            ['prefixes' => ['sales.', 'pos.checkout.'], 'path' => '/sales'],
            ['prefixes' => ['inventory.', 'catalogue.'], 'path' => '/inventory'],
            ['prefixes' => ['customers.'], 'path' => '/customers'],
            ['prefixes' => ['purchasing.'], 'path' => '/suppliers'],
            ['prefixes' => ['fulfillment.'], 'path' => '/fulfillment'],
            ['prefixes' => ['reports.'], 'path' => '/reports'],
            ['prefixes' => ['dashboard.'], 'path' => '/dashboard'],
        ],
    ],
    'admin' => [
        'label' => 'Administration',
        'description' => 'Users, roles, permissions, company setup, and system settings.',
        'icon' => 'building',
        'home_path' => '/admin',
        'domain_modules' => ['admin'],
        'permission_prefixes' => ['admin.'],
    ],
    'accounting' => [
        'label' => 'Accounting',
        'description' => 'General ledger, payments, receivables, expenses, and financial reports.',
        'icon' => 'chart',
        'home_path' => '/accounting',
        'domain_modules' => ['accounting', 'payments'],
        'permission_prefixes' => ['accounting.', 'payments.'],
    ],
    'hr' => [
        'label' => 'Human Resources',
        'description' => 'Employees, attendance, payroll, and HR reports.',
        'icon' => 'people',
        'home_path' => '/hr',
        'domain_modules' => ['hr_payroll'],
        'permission_prefixes' => ['hr.'],
    ],
];
