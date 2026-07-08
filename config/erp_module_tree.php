<?php

/**
 * Hierarchical ERP module tree.
 *
 * Domain roots (sales, inventory, …) are master switches. When a domain is off,
 * its dashboards, features, and reports are off regardless of child overrides.
 * When a domain is on, individual children (e.g. sales.reports) can be toggled per org.
 */
return [
    'sales' => [
        'label' => 'Sales',
        'nav_group' => 'Sales',
        'kind' => 'domain',
        'children' => ['sales.pos', 'sales.mobile', 'sales.backend', 'sales.dashboard', 'sales.reports'],
    ],
    'sales.pos' => [
        'label' => 'Point of sale',
        'nav_group' => 'Sales',
        'parent' => 'sales',
        'kind' => 'feature',
        'channel' => 'pos',
    ],
    'sales.mobile' => [
        'label' => 'Mobile sales',
        'nav_group' => 'Sales',
        'parent' => 'sales',
        'kind' => 'feature',
        'channel' => 'mobile',
    ],
    'sales.backend' => [
        'label' => 'Backoffice sales & orders',
        'nav_group' => 'Sales',
        'parent' => 'sales',
        'kind' => 'feature',
        'channel' => 'backend',
    ],
    'sales.dashboard' => [
        'label' => 'Sales dashboard',
        'nav_group' => 'Sales',
        'parent' => 'sales',
        'kind' => 'dashboard',
    ],
    'sales.reports' => [
        'label' => 'Sales reports',
        'nav_group' => 'Sales',
        'parent' => 'sales',
        'kind' => 'reports',
    ],

    'inventory' => [
        'label' => 'Inventory & stock',
        'nav_group' => 'Inventory',
        'kind' => 'domain',
        'children' => ['inventory.dashboard', 'inventory.reports'],
    ],
    'inventory.dashboard' => [
        'label' => 'Inventory dashboard',
        'nav_group' => 'Inventory',
        'parent' => 'inventory',
        'kind' => 'dashboard',
    ],
    'inventory.reports' => [
        'label' => 'Inventory reports',
        'nav_group' => 'Inventory',
        'parent' => 'inventory',
        'kind' => 'reports',
    ],

    'customers_suppliers' => [
        'label' => 'Customers, suppliers & purchasing',
        'nav_group' => 'Purchasing',
        'kind' => 'domain',
        'children' => ['customers_suppliers.dashboard', 'customers_suppliers.reports'],
    ],
    'customers_suppliers.dashboard' => [
        'label' => 'Purchasing dashboard',
        'nav_group' => 'Purchasing',
        'parent' => 'customers_suppliers',
        'kind' => 'dashboard',
    ],
    'customers_suppliers.reports' => [
        'label' => 'Purchasing reports',
        'nav_group' => 'Purchasing',
        'parent' => 'customers_suppliers',
        'kind' => 'reports',
    ],

    'accounting' => [
        'label' => 'Accounting & finance',
        'nav_group' => 'Accounting',
        'kind' => 'domain',
        'children' => ['payments', 'accounting.dashboard', 'accounting.reports'],
    ],
    'payments' => [
        'label' => 'Payments & receivables',
        'nav_group' => 'Accounting',
        'parent' => 'accounting',
        'kind' => 'feature',
    ],
    'accounting.dashboard' => [
        'label' => 'Accounting dashboard',
        'nav_group' => 'Accounting',
        'parent' => 'accounting',
        'kind' => 'dashboard',
    ],
    'accounting.reports' => [
        'label' => 'Financial reports',
        'nav_group' => 'Accounting',
        'parent' => 'accounting',
        'kind' => 'reports',
    ],

    'hr_payroll' => [
        'label' => 'Human resources & payroll',
        'nav_group' => 'Human resources',
        'kind' => 'domain',
        'children' => ['hr_payroll.dashboard', 'hr_payroll.reports'],
    ],
    'hr_payroll.dashboard' => [
        'label' => 'HR dashboard',
        'nav_group' => 'Human resources',
        'parent' => 'hr_payroll',
        'kind' => 'dashboard',
    ],
    'hr_payroll.reports' => [
        'label' => 'HR reports',
        'nav_group' => 'Human resources',
        'parent' => 'hr_payroll',
        'kind' => 'reports',
    ],

    'distribution' => [
        'label' => 'Distribution & logistics',
        'nav_group' => 'Distribution',
        'kind' => 'domain',
        'children' => ['distribution.dashboard', 'distribution.reports'],
    ],
    'distribution.dashboard' => [
        'label' => 'Logistics dashboard',
        'nav_group' => 'Distribution',
        'parent' => 'distribution',
        'kind' => 'dashboard',
    ],
    'distribution.reports' => [
        'label' => 'Logistics reports',
        'nav_group' => 'Distribution',
        'parent' => 'distribution',
        'kind' => 'reports',
    ],

    'admin' => [
        'label' => 'Administration',
        'nav_group' => 'Administration',
        'kind' => 'domain',
        'children' => [],
    ],

    /** Report API slug => reports module key */
    'report_modules' => [
        'daily-sales' => 'sales.reports',
        'sales-by-product' => 'sales.reports',
        'sales-by-supplier' => 'sales.reports',
        'sales-by-user' => 'sales.reports',
        'sales-by-customer' => 'sales.reports',
        'sales-by-channel' => 'sales.reports',
        'mobile-route-sales' => 'distribution.reports',
        'dispatch-trips' => 'distribution.reports',
        'trip-cash-settlement' => 'distribution.reports',
        'pod-compliance' => 'distribution.reports',
        'driver-deliveries' => 'distribution.reports',
        'sales-pipeline' => 'sales.reports',
        'vat-collected' => 'sales.reports',
        'category-sales' => 'sales.reports',
        'discount-summary' => 'sales.reports',
        'payment-collection' => 'sales.reports',
        'credit-outstanding' => 'sales.reports',
        'till-sessions' => 'sales.reports',
        'eod-cashier' => 'sales.reports',
        'eod-report' => 'sales.reports',
        'returns' => 'sales.reports',

        'items-currently-in-stock' => 'inventory.reports',
        'stock-on-hand' => 'inventory.reports',
        'low-stock' => 'inventory.reports',
        'stock-movement' => 'inventory.reports',
        'stock-chain' => 'inventory.reports',
        'stock-valuation' => 'inventory.reports',
        'inventory-valuation-summary' => 'inventory.reports',
        'stock-reservations' => 'inventory.reports',
        'stock-receipts' => 'inventory.reports',
        'stock-transfers' => 'inventory.reports',
        'branch-stock-transfers' => 'inventory.reports',
        'damages' => 'inventory.reports',
        'price-list' => 'inventory.reports',

        'profit-loss' => 'accounting.reports',
        'top-debtors' => 'accounting.reports',
        'expenses' => 'accounting.reports',
        'ar-aging' => 'accounting.reports',
        'invoice-payments' => 'accounting.reports',
        'journal-register' => 'accounting.reports',
        'general-ledger' => 'accounting.reports',
        'trial-balance' => 'accounting.reports',
        'balance-sheet' => 'accounting.reports',
        'profit-loss-gl' => 'accounting.reports',
        'cash-flow' => 'accounting.reports',
        'accounts-receivable' => 'accounting.reports',
        'accounts-payable' => 'accounting.reports',
        'subledger-reconciliation' => 'accounting.reports',
        'kra-receipts' => 'accounting.reports',

        'purchases-by-supplier' => 'customers_suppliers.reports',
        'open-lpo' => 'customers_suppliers.reports',
        'supplier-returns' => 'customers_suppliers.reports',

        'payroll-summary' => 'hr_payroll.reports',
        'leave-balance' => 'hr_payroll.reports',
        'statutory-deductions' => 'hr_payroll.reports',
        'bank-transfer' => 'hr_payroll.reports',
        'staff-turnover' => 'hr_payroll.reports',
        'headcount' => 'hr_payroll.reports',
        'contract-expiry' => 'hr_payroll.reports',
        'hr-dashboard-kpi' => 'hr_payroll.reports',

        'audit-trail' => 'admin',
    ],

    /*
    | Operational finance reports usable without the full accounting domain.
    | EnsureReportModule allows access when any listed module is enabled.
    */
    'backoffice_finance_reports' => [
        'profit-loss',
        'top-debtors',
        'ar-aging',
        'expenses',
        'invoice-payments',
        'kra-receipts',
    ],
];
