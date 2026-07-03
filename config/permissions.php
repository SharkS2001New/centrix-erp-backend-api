<?php

/**
 * Permission codes used by erp.permission middleware.
 * Map route groups in routes to these codes.
 */
return [
    'sales.create' => 'Process sales, carts, checkout',
    'sales.manage' => 'Update order workflow / transitions',
    'sales.view' => 'View sales orders and related records',
    'mobile.access' => 'Use the LightStores mobile field sales app',
    'driver.mobile' => 'Use the mobile driver delivery app',
    'payments.manage' => 'Record payments on sales and AR',
    'payments.view' => 'View payment and invoice records',
    'inventory.manage' => 'Stock adjust, transfer, receive, returns',
    'inventory.view' => 'View stock availability',
    'catalogue.view' => 'View product catalogue',
    'reports.view' => 'Run reports',
    'reports.builder' => 'Build custom reports',
    'ai.assist' => 'Use AI assistant',
    'purchasing.view' => 'View LPOs, suppliers, and supplier payments',
    'purchasing.manage' => 'LPO and supplier operations',
    'customers.view' => 'View customers and statements',
    'customers.manage' => 'Manage customers',
    'fulfillment.view' => 'View drivers, vehicles, and routes',
    'fulfillment.manage' => 'Manage drivers, vehicles, and routes',
    'accounting.view' => 'View chart of accounts and journals',
    'accounting.manage' => 'Create, post, and reverse journal entries',
    'hr.view' => 'View HR records',
    'hr.manage' => 'Payroll processing',
    'admin.view' => 'View administration settings',
    'admin.manage' => 'Users, roles, org settings',
    'pos.till' => 'Open/close till sessions and X/Z reports',
    'products.manage' => 'Product catalogue and KRA device register',
];
