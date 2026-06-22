<?php

/**
 * Tenant applications toggled by the platform super admin at registration.
 *
 * These map to login workspaces and underlying enabled_modules keys via
 * App\Services\Erp\ApplicationProvisioner.
 */
return [
    'order' => [
        'pos',
        'backoffice',
        'distribution',
        'accounting',
        'hr',
        'admin',
    ],

    'definitions' => [
        'pos' => [
            'label' => 'External ERP',
            'description' => 'External POS terminal, mobile field sales, till sessions, and end of day. Turning this on also enables Backoffice.',
            'icon' => 'pos',
        ],
        'backoffice' => [
            'label' => 'Backoffice',
            'description' => 'Sales, inventory, purchasing, and day-to-day operations.',
            'icon' => 'building',
        ],
        'distribution' => [
            'label' => 'Distribution',
            'description' => 'Dispatch, trips, fleet, proof of delivery, and logistics reports.',
            'icon' => 'truck',
        ],
        'accounting' => [
            'label' => 'Accounting',
            'description' => 'General ledger, payments, receivables, expenses, and financial reports.',
            'icon' => 'chart',
        ],
        'hr' => [
            'label' => 'Human Resources',
            'description' => 'Employees, attendance, payroll, and HR reports.',
            'icon' => 'people',
        ],
        'admin' => [
            'label' => 'Administration',
            'description' => 'Users, roles, branches, company setup, and organization settings. When disabled, configure the tenant from the platform instead.',
            'icon' => 'settings',
        ],
    ],
];
