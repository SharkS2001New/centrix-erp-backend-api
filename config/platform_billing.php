<?php

/**
 * Default billing line items for platform invoices (super admin → tenant).
 * Amounts are suggestions; super admin can edit on each invoice.
 */
return [
    'currency' => 'KES',

    'modules' => [
        'sales' => [
            'label' => 'Sales',
            'description' => 'Sales module — orders, quotations, pricing, and sales reporting.',
            'default_amount' => 15000,
            'billing_period' => 'monthly',
        ],
        'sales.pos' => [
            'label' => 'Point of sale',
            'description' => 'External POS terminal, till sessions, and end-of-day reconciliation.',
            'default_amount' => 8000,
            'billing_period' => 'monthly',
        ],
        'sales.mobile' => [
            'label' => 'Mobile field sales',
            'description' => 'Mobile order capture, route sales, and field rep workflows.',
            'default_amount' => 12000,
            'billing_period' => 'monthly',
        ],
        'inventory' => [
            'label' => 'Inventory & stock',
            'description' => 'Stock control, transfers, valuations, and inventory reporting.',
            'default_amount' => 10000,
            'billing_period' => 'monthly',
        ],
        'customers_suppliers' => [
            'label' => 'Customers, suppliers & purchasing',
            'description' => 'Customer and supplier master data, LPOs, purchases, and routes.',
            'default_amount' => 8000,
            'billing_period' => 'monthly',
        ],
        'accounting' => [
            'label' => 'Accounting & finance',
            'description' => 'General ledger, receivables, expenses, and financial statements.',
            'default_amount' => 18000,
            'billing_period' => 'monthly',
        ],
        'hr_payroll' => [
            'label' => 'Human resources & payroll',
            'description' => 'Employees, attendance, payroll runs, and HR compliance reports.',
            'default_amount' => 15000,
            'billing_period' => 'monthly',
        ],
        'distribution' => [
            'label' => 'Distribution & logistics',
            'description' => 'Dispatch board, trips, fleet, proof of delivery, and logistics KPIs.',
            'default_amount' => 14000,
            'billing_period' => 'monthly',
        ],
        'admin' => [
            'label' => 'Administration',
            'description' => 'Tenant self-service admin — users, roles, branches, and company settings.',
            'default_amount' => 0,
            'billing_period' => 'monthly',
            'billable' => false,
        ],
        'platform.ai' => [
            'label' => 'AI assistant',
            'description' => 'Centrix AI assistant — natural language search, insights, and guided workflows.',
            'default_amount' => 6000,
            'billing_period' => 'monthly',
            'platform_flag' => 'ai',
        ],
        'platform.kra' => [
            'label' => 'KRA eTIMS integration',
            'description' => 'Kenya Revenue Authority eTIMS receipt submission and compliance.',
            'default_amount' => 4000,
            'billing_period' => 'monthly',
            'platform_flag' => 'kra',
        ],
        'platform.mpesa' => [
            'label' => 'M-Pesa STK push',
            'description' => 'Platform-managed Lipa na M-Pesa STK checkout for POS and mobile sales.',
            'default_amount' => 3500,
            'billing_period' => 'monthly',
            'platform_flag' => 'mpesa',
        ],
        'platform.advanced_import' => [
            'label' => 'Advanced data import',
            'description' => 'Bulk CSV import pipelines for products, customers, stock, and migration tools.',
            'default_amount' => 0,
            'billing_period' => 'monthly',
            'platform_flag' => 'advanced_import',
            'billable' => false,
        ],
        'platform.hosting' => [
            'label' => 'Platform hosting & support',
            'description' => 'Cloud hosting, backups, security updates, and standard support SLA.',
            'default_amount' => 20000,
            'billing_period' => 'monthly',
            'always_available' => true,
        ],
    ],
];
