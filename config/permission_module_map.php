<?php

/**
 * Maps permission registry module keys (permission_registry.groups) to ERP module keys.
 * A registry module is considered enabled when any mapped ERP module is enabled for the org.
 */
return [
    'dashboard' => [
        'sales.dashboard',
        'inventory.dashboard',
        'accounting.dashboard',
        'hr_payroll.dashboard',
        'distribution.dashboard',
    ],
    'catalogue' => ['inventory', 'sales.backend', 'sales.pos'],
    'customers' => ['customers_suppliers', 'sales.backend', 'sales.mobile'],
    'sales' => ['sales.backend', 'sales.dashboard', 'sales.pos'],
    'mobile' => ['sales.mobile'],
    'pos' => ['sales.pos'],
    'payments' => ['payments', 'accounting'],
    'inventory' => ['inventory'],
    'purchasing' => ['customers_suppliers'],
    'fulfillment' => ['distribution'],
    'accounting' => ['accounting', 'payments'],
    'reports' => [
        'sales.reports',
        'inventory.reports',
        'accounting.reports',
        'customers_suppliers.reports',
        'hr_payroll.reports',
        'distribution.reports',
    ],
    'hr' => ['hr_payroll'],
    'admin' => ['admin'],
];
