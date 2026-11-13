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
        'hotel_bar_pos',
        'hospitality_backoffice',
        'distribution',
        'accounting',
        'hr',
        'admin',
        'spa',
        'driving',
    ],

    'definitions' => [
        'pos' => [
            'label' => 'External POS',
            'description' => 'External POS terminal and checkout. Till management and end of day stay under Backoffice. Turning this on also enables Backoffice.',
            'icon' => 'pos',
        ],
        'backoffice' => [
            'label' => 'Backoffice',
            'description' => 'Sales, inventory, purchasing, and day-to-day operations.',
            'icon' => 'building',
        ],
        'hotel_bar_pos' => [
            'label' => 'Hotel POS',
            'description' => 'Kenya-ready hotel front POS (bar, restaurant, room charge). Uses hospitality checks — not retail sales tables. Turning this on also enables Hotel Backoffice (including stock & LPO).',
            'icon' => 'pos',
        ],
        'hospitality_backoffice' => [
            'label' => 'Hotel Backoffice',
            'description' => 'Rooms, front desk, folios, housekeeping, menu products, LPO, stock receiving, and hotel reports. Does not use retail sales order tables.',
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
        'spa' => [
            'label' => 'SPA & Kinyozi',
            'description' => 'One salon and barbershop workspace for appointments, clients, stock, administration, staff, and payroll. Does not use retail or hotel tills.',
            'icon' => 'spa',
        ],
        'driving' => [
            'label' => 'Driving School',
            'description' => 'Students, courses, lesson scheduling, instructors, training vehicles, fees, and school reports.',
            'icon' => 'truck',
        ],
    ],
];
