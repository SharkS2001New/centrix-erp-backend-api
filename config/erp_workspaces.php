<?php

/**
 * Product workspaces — same login, different shells after sign-in.
 *
 * Order here is the default application switcher order (web also sorts client-side).
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
        'description' => 'Sales, inventory, purchasing, and day-to-day operations.',
        'icon' => 'building',
        'home_path' => '/dashboard',
        'module_keys' => ['sales.backend'],
        'domain_modules' => ['inventory', 'customers_suppliers'],
        'permission_prefixes' => [
            'catalogue.',
            'customers.',
            'sales.',
            'inventory.',
            'purchasing.',
            'reports.',
        ],
        'home_path_by_permissions' => [
            ['prefixes' => ['inventory.'], 'path' => '/inventory/stock'],
            ['prefixes' => ['purchasing.'], 'path' => '/purchasing/lpo'],
            ['prefixes' => ['sales.'], 'path' => '/sales/orders'],
            ['prefixes' => ['catalogue.'], 'path' => '/products'],
            ['prefixes' => ['customers.'], 'path' => '/customers'],
        ],
    ],
    'hotel_bar_pos' => [
        'label' => 'Hotel & Bar POS',
        'description' => 'Hospitality front POS for bars, restaurants, and room charging.',
        'icon' => 'pos',
        'home_path' => '/hotel-bar-pos',
        'module_keys' => ['hospitality.bar_pos'],
        'permission_prefixes' => ['hotel_bar_pos.'],
        'entry_permission' => 'hotel_bar_pos.terminal.view',
    ],
    'hospitality_backoffice' => [
        'label' => 'Hospitality Backoffice',
        'description' => 'Rooms, front desk, folios, housekeeping, and hotel operations.',
        'icon' => 'building',
        'home_path' => '/hospitality',
        'module_keys' => ['hospitality.backend'],
        'domain_modules' => ['hospitality'],
        'permission_prefixes' => [
            'hospitality.',
            'hotel_bar_pos.',
            'inventory.',
            'catalogue.',
        ],
        'entry_permission' => 'hospitality.dashboard.view',
    ],
    'distribution' => [
        'label' => 'Distribution',
        'description' => 'Dispatch, trips, fleet, proof of delivery, and logistics reports.',
        'icon' => 'truck',
        'home_path' => '/fulfillment',
        'domain_modules' => ['distribution'],
        'permission_prefixes' => ['fulfillment.'],
        'entry_permission' => 'fulfillment.drivers.view',
        'home_path_by_permissions' => [
            // Prefer the distribution dashboard whenever the user can open it.
            ['prefixes' => ['fulfillment.overview.'], 'path' => '/fulfillment'],
            ['prefixes' => ['fulfillment.dispatch.'], 'path' => '/fulfillment/dispatch'],
            ['prefixes' => ['fulfillment.trips.'], 'path' => '/fulfillment/trips'],
            ['prefixes' => ['fulfillment.picking.'], 'path' => '/fulfillment/picking'],
            ['prefixes' => ['fulfillment.loading_lists.'], 'path' => '/fulfillment/loading-lists'],
            ['prefixes' => ['fulfillment.routes.'], 'path' => '/fulfillment/routes'],
            ['prefixes' => ['fulfillment.drivers.'], 'path' => '/fulfillment/drivers'],
            ['prefixes' => ['fulfillment.vehicles.'], 'path' => '/fulfillment/vehicles'],
            ['prefixes' => ['fulfillment.schedules.'], 'path' => '/fulfillment/schedules'],
            ['prefixes' => ['fulfillment.pod.'], 'path' => '/fulfillment/pod-records'],
        ],
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
    'admin' => [
        'label' => 'Administration',
        'description' => 'Users, roles, permissions, company setup, and system settings.',
        'icon' => 'building',
        'home_path' => '/admin',
        'domain_modules' => ['admin'],
        'permission_prefixes' => ['admin.'],
    ],
];
