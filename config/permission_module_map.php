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
        'hospitality.dashboard',
    ],
    'catalogue' => ['inventory', 'sales.backend', 'sales.pos', 'hospitality.bar_pos', 'hospitality.backend'],
    'customers' => ['customers_suppliers', 'sales.backend', 'sales.mobile'],
    'sales' => ['sales.backend', 'sales.dashboard', 'sales.pos'],
    'mobile_sales' => ['sales.mobile'],
    'mobile_driver' => ['distribution', 'sales.mobile'],
    'mobile_manager' => ['sales.backend'],
    'pos' => ['sales.pos'],
    'hotel_bar_pos' => ['hospitality.bar_pos'],
    'hospitality' => ['hospitality.backend', 'hospitality.dashboard', 'hospitality.reports'],
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
        'hospitality.reports',
    ],
    'hr' => ['hr_payroll'],
    'admin' => ['admin'],
];
