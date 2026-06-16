<?php

/**
 * Feature-level permission registry.
 *
 * Codes: {module}.{feature}.{action}
 * Used by PermissionMatrixService, roles UI, and permission_aliases for route middleware.
 */
return [
    'groups' => [
        'dashboard' => [
            'label' => 'Dashboard',
            'features' => [
                'overview' => [
                    'label' => 'Dashboard',
                    'actions' => ['view'],
                ],
            ],
        ],
        'catalogue' => [
            'label' => 'Catalog',
            'features' => [
                'products' => ['label' => 'Products', 'actions' => ['view', 'create', 'edit', 'delete']],
                'categories' => ['label' => 'Categories & subcategories', 'actions' => ['view', 'create', 'edit', 'delete']],
                'uoms' => ['label' => 'Units of measure', 'actions' => ['view', 'create', 'edit', 'delete']],
                'retail_packages' => ['label' => 'Retail packages', 'actions' => ['view', 'edit']],
                'vat_rates' => ['label' => 'VAT rates', 'actions' => ['view', 'create', 'edit', 'delete']],
                'price_history' => ['label' => 'Price history', 'actions' => ['view']],
            ],
        ],
        'customers' => [
            'label' => 'Customers',
            'features' => [
                'customers' => ['label' => 'Customers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'statements' => ['label' => 'Customer statements', 'actions' => ['view']],
            ],
        ],
        'sales' => [
            'label' => 'Sales',
            'features' => [
                'dashboard' => ['label' => 'Sales dashboard', 'actions' => ['view']],
                'orders' => ['label' => 'Orders & queues', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'carts' => ['label' => 'Carts (read-only)', 'actions' => ['view']],
                'vouchers' => ['label' => 'Vouchers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'loyalty_cards' => ['label' => 'Loyalty cards', 'actions' => ['view', 'create', 'edit', 'delete']],
                'reservations' => ['label' => 'Reservations', 'actions' => ['view', 'create', 'edit', 'delete']],
                'returns' => ['label' => 'Customer returns', 'actions' => ['view', 'create']],
            ],
        ],
        'pos' => [
            'label' => 'Point of sale',
            'features' => [
                'till_management' => ['label' => 'Till management', 'actions' => ['view', 'create', 'edit']],
                'checkout' => ['label' => 'POS checkout', 'actions' => ['create']],
                'end_of_day' => ['label' => 'End of day report', 'actions' => ['view']],
            ],
        ],
        'payments' => [
            'label' => 'Payments',
            'features' => [
                'sale_payments' => ['label' => 'Sale payments', 'actions' => ['view', 'create', 'edit']],
                'customer_invoices' => ['label' => 'Customer invoices', 'actions' => ['view', 'create', 'edit']],
                'customer_payments' => ['label' => 'Customer invoice payments', 'actions' => ['view', 'create', 'edit']],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'features' => [
                'stock' => ['label' => 'Current stock', 'actions' => ['view']],
                'receipts' => ['label' => 'Stock receipts', 'actions' => ['view', 'create', 'approve']],
                'movements' => ['label' => 'Stock movements', 'actions' => ['view']],
                'transfers' => ['label' => 'Stock transfers', 'actions' => ['view', 'create']],
                'damages' => ['label' => 'Damages', 'actions' => ['view', 'create']],
                'stock_take' => ['label' => 'Stock take', 'actions' => ['view', 'create', 'approve']],
            ],
        ],
        'purchasing' => [
            'label' => 'Procurement',
            'features' => [
                'lpo' => ['label' => 'Purchase orders', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'suppliers' => ['label' => 'Suppliers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'supplier_payments' => ['label' => 'Supplier payments', 'actions' => ['view', 'create']],
                'supplier_returns' => ['label' => 'Supplier returns', 'actions' => ['view', 'create']],
            ],
        ],
        'fulfillment' => [
            'label' => 'Fulfillment',
            'features' => [
                'drivers' => ['label' => 'Drivers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'vehicles' => ['label' => 'Vehicles', 'actions' => ['view', 'create', 'edit', 'delete']],
                'routes' => ['label' => 'Routes', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'features' => [
                'dashboard' => ['label' => 'Accounting dashboard', 'actions' => ['view']],
                'chart_of_accounts' => ['label' => 'Chart of accounts', 'actions' => ['view', 'create', 'edit', 'delete']],
                'journal_entries' => ['label' => 'Journal entries', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'fiscal_periods' => ['label' => 'Fiscal periods', 'actions' => ['view', 'edit', 'approve']],
                'settings' => ['label' => 'Accounting settings', 'actions' => ['view', 'edit']],
                'account_mappings' => ['label' => 'Account mappings', 'actions' => ['view', 'edit']],
                'export_queue' => ['label' => 'Export queue', 'actions' => ['view']],
                'general_ledger' => ['label' => 'General ledger', 'actions' => ['view']],
                'trial_balance' => ['label' => 'Trial balance', 'actions' => ['view']],
                'profit_loss' => ['label' => 'Profit & loss', 'actions' => ['view']],
                'balance_sheet' => ['label' => 'Balance sheet', 'actions' => ['view']],
                'cash_flow' => ['label' => 'Cash flow', 'actions' => ['view']],
                'accounts_receivable' => ['label' => 'Accounts receivable', 'actions' => ['view']],
                'accounts_payable' => ['label' => 'Accounts payable', 'actions' => ['view']],
                'expenses' => ['label' => 'Expenses', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'features' => [
                'hub' => ['label' => 'All reports', 'actions' => ['view']],
                'daily_sales' => ['label' => 'Daily sales', 'actions' => ['view']],
                'stock_on_hand' => ['label' => 'Stock on hand', 'actions' => ['view']],
                'profit_loss' => ['label' => 'Profit & loss', 'actions' => ['view']],
                'top_debtors' => ['label' => 'Top debtors', 'actions' => ['view']],
                'stock_movement' => ['label' => 'Stock movement', 'actions' => ['view']],
                'vat_collected' => ['label' => 'VAT collected', 'actions' => ['view']],
                'till_sessions' => ['label' => 'Till sessions', 'actions' => ['view']],
                'expenses' => ['label' => 'Expenses report', 'actions' => ['view']],
                'customer_statement' => ['label' => 'Customer statement', 'actions' => ['view']],
                'builder' => ['label' => 'Report builder', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],
        'ai' => [
            'label' => 'AI assistant',
            'features' => [
                'assist' => ['label' => 'AI assistant', 'actions' => ['create']],
            ],
        ],
        'hr' => [
            'label' => 'HR & Payroll',
            'features' => [
                'employees' => ['label' => 'Employees', 'actions' => ['view', 'create', 'edit', 'delete']],
                'departments' => ['label' => 'Departments', 'actions' => ['view', 'create', 'edit', 'delete']],
                'positions' => ['label' => 'Positions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'kpis' => ['label' => 'Employee KPIs', 'actions' => ['view', 'create', 'edit', 'delete']],
                'shifts' => ['label' => 'Shifts', 'actions' => ['view', 'create', 'edit', 'delete']],
                'allowances' => ['label' => 'Allowances', 'actions' => ['view', 'create', 'edit', 'delete']],
                'deductions' => ['label' => 'Deductions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'overtime' => ['label' => 'Overtime', 'actions' => ['view', 'create', 'edit', 'delete']],
                'cash_advances' => ['label' => 'Cash advances', 'actions' => ['view', 'create', 'edit', 'approve']],
                'attendance' => ['label' => 'Attendance', 'actions' => ['view', 'create']],
                'leave' => ['label' => 'Leave & off days', 'actions' => ['view', 'create', 'edit', 'approve']],
                'payroll' => ['label' => 'Payroll', 'actions' => ['view', 'create', 'approve']],
                'holidays' => ['label' => 'Public holidays', 'actions' => ['view', 'create', 'edit', 'delete']],
                'leave_settings' => ['label' => 'Leave settings', 'actions' => ['view', 'edit']],
            ],
        ],
        'admin' => [
            'label' => 'Administration',
            'features' => [
                'overview' => ['label' => 'Admin overview', 'actions' => ['view']],
                'company' => ['label' => 'Company profile', 'actions' => ['view', 'edit']],
                'branches' => ['label' => 'Branches', 'actions' => ['view', 'create', 'edit', 'delete']],
                'users' => ['label' => 'Users', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'Roles & permissions', 'actions' => ['view', 'edit']],
                'audit' => ['label' => 'Audit trail', 'actions' => ['view']],
                'settings' => ['label' => 'System settings', 'actions' => ['view', 'edit']],
                'payment_methods' => ['label' => 'Payment methods', 'actions' => ['view', 'create', 'edit', 'delete']],
            ],
        ],
    ],
];
