<?php

/**
 * Permission codes used by erp.permission middleware.
 * Map route groups in routes to these codes.
 */
return [
    'sales.create' => 'Process sales, carts, checkout',
    'sales.manage' => 'Update order workflow / transitions',
    'payments.manage' => 'Record payments on sales and AR',
    'inventory.manage' => 'Stock adjust, transfer, receive, returns',
    'inventory.view' => 'View stock availability',
    'reports.view' => 'Run reports',
    'purchasing.manage' => 'LPO and supplier operations',
    'accounting.manage' => 'Journal entries',
    'hr.manage' => 'Payroll processing',
    'admin.manage' => 'Users, roles, org settings',
    'pos.till' => 'Open/close till sessions and X/Z reports',
];
