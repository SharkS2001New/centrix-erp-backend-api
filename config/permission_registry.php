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
                    'label' => 'Business summary',
                    'actions' => ['view'],
                ],
            ],
        ],
        'catalogue' => [
            'label' => 'Products & catalog',
            'features' => [
                'products' => ['label' => 'Products catalogue', 'actions' => ['view', 'create', 'edit', 'delete']],
                'categories' => ['label' => 'Categories & subcategories', 'actions' => ['view', 'create', 'edit', 'delete']],
                'uoms' => ['label' => 'Units of measure', 'actions' => ['view', 'create', 'edit', 'delete']],
                'retail_packages' => ['label' => 'Retail packages', 'actions' => ['view', 'edit']],
                'vat_rates' => ['label' => 'VAT / tax rates', 'actions' => ['view', 'create', 'edit', 'delete']],
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
            'label' => 'Sales & orders',
            'features' => [
                'dashboard' => ['label' => 'Sales analytics', 'actions' => ['view']],
                'orders' => ['label' => 'Order actions', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'discounts' => ['label' => 'Discounts', 'actions' => ['give']],
                ...\App\Support\SalesOrderQueuePermissions::registryFeatures(),
                'carts' => ['label' => 'Carts (read-only)', 'actions' => ['view']],
                'vouchers' => ['label' => 'Vouchers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'loyalty_cards' => ['label' => 'Loyalty cards', 'actions' => ['view', 'create', 'edit', 'delete']],
                'reservations' => ['label' => 'Reservations', 'actions' => ['view', 'create', 'edit', 'delete']],
                'returns' => ['label' => 'Returns & credit notes', 'actions' => ['view', 'create']],
                'loading_sheets' => ['label' => 'Loading sheets', 'actions' => ['view']],
                'field_attendance' => ['label' => 'Field attendance', 'actions' => ['view', 'create']],
                'legacy_orders' => ['label' => 'Legacy orders', 'actions' => ['view']],
            ],
        ],
        'mobile_sales' => [
            'label' => 'Sales rep',
            'features' => [
                'dashboard' => ['label' => 'Dashboard & KPIs', 'actions' => ['view']],
                'orders' => ['label' => 'Orders & checkout', 'actions' => ['view', 'create', 'edit']],
                'customers' => ['label' => 'Customers', 'actions' => ['view', 'create', 'edit']],
                'catalog' => ['label' => 'Product search', 'actions' => ['view']],
                'stock' => ['label' => 'Stock levels', 'actions' => ['view']],
                'routes' => ['label' => 'Route selection', 'actions' => ['view']],
                'payments' => ['label' => 'Collect payments', 'actions' => ['view', 'create']],
            ],
        ],
        'mobile_driver' => [
            'label' => 'Driver',
            'features' => [
                'trips' => ['label' => 'Assigned trips', 'actions' => ['view']],
                'deliveries' => ['label' => 'Deliveries', 'actions' => ['view', 'deliver']],
                'pod' => ['label' => 'Proof of delivery', 'actions' => ['view', 'create']],
                'cash' => ['label' => 'Cash collection', 'actions' => ['view', 'create']],
            ],
        ],
        'mobile_manager' => [
            'label' => 'Manager app',
            'features' => [
                'app' => ['label' => 'App access', 'actions' => ['access']],
                'dashboard' => ['label' => 'Executive dashboard', 'actions' => ['view']],
                'approvals' => ['label' => 'Approvals inbox', 'actions' => ['view', 'approve', 'reject']],
                'notifications' => ['label' => 'Notifications', 'actions' => ['view', 'manage']],
                'reports' => ['label' => 'Reports hub', 'actions' => ['view']],
                'users' => ['label' => 'Users', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'Roles & permissions', 'actions' => ['view', 'edit']],
            ],
        ],
        'pos' => [
            'label' => 'External POS',
            'features' => [
                'terminal' => ['label' => 'POS terminal', 'actions' => ['view']],
                'checkout' => ['label' => 'Create order', 'actions' => ['create']],
                'till_management' => ['label' => 'Till management', 'actions' => ['view', 'create', 'edit']],
                'end_of_day' => ['label' => 'End of day report', 'actions' => ['view']],
            ],
        ],
        'payments' => [
            'label' => 'Payments & invoicing',
            'features' => [
                'sale_payments' => ['label' => 'Sale payments', 'actions' => ['view', 'create', 'edit']],
                'customer_invoices' => ['label' => 'Customer invoices', 'actions' => ['view', 'create', 'edit']],
                'customer_payments' => ['label' => 'Customer invoice payments', 'actions' => ['view', 'create', 'edit']],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'features' => [
                'stock' => ['label' => 'Items currently in stock', 'actions' => ['view']],
                'stock_take' => ['label' => 'Stock take', 'actions' => ['view', 'create', 'approve']],
                'movements' => ['label' => 'Inventory movements', 'actions' => ['view']],
                'transfers' => ['label' => 'Stock transfers', 'actions' => ['view', 'create']],
                'receipts' => ['label' => 'Goods received', 'actions' => ['view', 'create', 'approve']],
                'adjustments' => ['label' => 'Stock adjustments', 'actions' => ['view', 'create']],
                'damages' => ['label' => 'Write-offs & damages', 'actions' => ['view', 'create']],
            ],
        ],
        'purchasing' => [
            'label' => 'Suppliers & procurement',
            'features' => [
                'suppliers' => ['label' => 'Suppliers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'lpo' => ['label' => 'Purchase orders (LPO)', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'supplier_payments' => ['label' => 'Supplier payments', 'actions' => ['view', 'create']],
                'supplier_returns' => ['label' => 'Supplier returns', 'actions' => ['view', 'create']],
            ],
        ],
        'fulfillment' => [
            'label' => 'Distribution & logistics',
            'features' => [
                'overview' => ['label' => 'Distribution overview', 'actions' => ['view']],
                'routes' => ['label' => 'Routes', 'actions' => ['view', 'create', 'edit', 'delete']],
                'schedules' => ['label' => 'Route schedules', 'actions' => ['view', 'edit']],
                'drivers' => ['label' => 'Drivers', 'actions' => ['view', 'create', 'edit', 'delete']],
                'vehicles' => ['label' => 'Vehicles', 'actions' => ['view', 'create', 'edit', 'delete']],
                'dispatch' => ['label' => 'Dispatch board', 'actions' => ['view', 'manage']],
                'trips' => ['label' => 'Trips', 'actions' => ['view', 'create', 'edit', 'delete']],
                'picking' => ['label' => 'Warehouse picking', 'actions' => ['view', 'edit']],
                'loading_lists' => ['label' => 'Loading lists', 'actions' => ['view']],
                'pod' => ['label' => 'Proof of delivery records', 'actions' => ['view']],
            ],
        ],
        'accounting' => [
            'label' => 'Accounting & finance',
            'features' => [
                'dashboard' => ['label' => 'Finance overview', 'actions' => ['view']],
                'accounts_receivable' => ['label' => 'Receivables ledger', 'actions' => ['view']],
                'accounts_payable' => ['label' => 'Payables ledger', 'actions' => ['view']],
                'chart_of_accounts' => ['label' => 'Chart of accounts', 'actions' => ['view', 'create', 'edit', 'delete']],
                'journal_entries' => ['label' => 'Journal entries', 'actions' => ['view', 'create', 'edit', 'delete', 'approve']],
                'general_ledger' => ['label' => 'General ledger', 'actions' => ['view']],
                'bank_reconciliation' => ['label' => 'Bank reconciliation', 'actions' => ['view', 'manage']],
                'trial_balance' => ['label' => 'Trial balance', 'actions' => ['view']],
                'balance_sheet' => ['label' => 'Balance sheet', 'actions' => ['view']],
                'profit_loss' => ['label' => 'Profit & loss', 'actions' => ['view']],
                'cash_flow' => ['label' => 'Cash flow statement', 'actions' => ['view']],
                'expenses' => ['label' => 'Expenses', 'actions' => ['view', 'create', 'edit', 'delete']],
                'fiscal_periods' => ['label' => 'Fiscal periods', 'actions' => ['view', 'edit', 'approve']],
                'account_mappings' => ['label' => 'Account mappings', 'actions' => ['view', 'edit']],
                'export_queue' => ['label' => 'Export queue', 'actions' => ['view']],
                'settings' => ['label' => 'Accounting settings', 'actions' => ['view', 'edit']],
            ],
        ],
        'reports' => [
            'label' => 'Reports',
            'features' => [
                'hub' => ['label' => 'Report overview', 'actions' => ['view']],
                'builder' => ['label' => 'Report builder', 'actions' => ['view', 'create', 'edit', 'delete']],
                'daily_sales' => ['label' => 'Daily sales', 'actions' => ['view']],
                'stock_on_hand' => ['label' => 'Stock on hand', 'actions' => ['view']],
                'stock_movement' => ['label' => 'Stock movement', 'actions' => ['view']],
                'profit_loss' => ['label' => 'Profit & loss', 'actions' => ['view']],
                'top_debtors' => ['label' => 'Top debtors', 'actions' => ['view']],
                'vat_collected' => ['label' => 'VAT collected', 'actions' => ['view']],
                'till_sessions' => ['label' => 'Till sessions', 'actions' => ['view']],
                'expenses' => ['label' => 'Expenses report', 'actions' => ['view']],
                'customer_statement' => ['label' => 'Customer statement', 'actions' => ['view']],
                'journal_register' => ['label' => 'Journal register', 'actions' => ['view']],
                'ar_aging' => ['label' => 'AR aging', 'actions' => ['view']],
                'dispatch_trips' => ['label' => 'Dispatch trips', 'actions' => ['view']],
                'driver_deliveries' => ['label' => 'Driver deliveries', 'actions' => ['view']],
                'payroll_summary' => ['label' => 'Payroll summary', 'actions' => ['view']],
                'legacy_archive' => ['label' => 'Legacy sales archive', 'actions' => ['view']],
            ],
        ],
        'ai' => [
            'label' => 'AI assistant',
            'features' => [
                'assist' => ['label' => 'AI assistant', 'actions' => ['create']],
            ],
        ],
        'hr' => [
            'label' => 'Human resources',
            'features' => [
                'employees' => ['label' => 'Employees', 'actions' => ['view', 'create', 'edit', 'delete']],
                'departments' => ['label' => 'Departments', 'actions' => ['view', 'create', 'edit', 'delete']],
                'positions' => ['label' => 'Positions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'kpis' => ['label' => 'KPIs', 'actions' => ['view', 'create', 'edit', 'delete']],
                'attendance' => ['label' => 'Attendance', 'actions' => ['view', 'create']],
                'leave' => ['label' => 'Leave', 'actions' => ['view', 'create', 'edit', 'approve']],
                'shifts' => ['label' => 'Shifts', 'actions' => ['view', 'create', 'edit', 'delete']],
                'overtime' => ['label' => 'Overtime', 'actions' => ['view', 'create', 'edit', 'delete']],
                'allowances' => ['label' => 'Allowances', 'actions' => ['view', 'create', 'edit', 'delete']],
                'deductions' => ['label' => 'Deductions', 'actions' => ['view', 'create', 'edit', 'delete']],
                'cash_advances' => ['label' => 'Cash advances', 'actions' => ['view', 'create', 'edit', 'approve']],
                'payroll' => ['label' => 'Payroll runs', 'actions' => ['view', 'create', 'approve']],
                'holidays' => ['label' => 'Public holidays', 'actions' => ['view', 'create', 'edit', 'delete']],
                'leave_settings' => ['label' => 'Leave settings', 'actions' => ['view', 'edit']],
            ],
        ],
        'admin' => [
            'label' => 'Administration',
            'features' => [
                'overview' => ['label' => 'Admin home', 'actions' => ['view']],
                'company' => ['label' => 'Company profile', 'actions' => ['view', 'edit']],
                'settings' => ['label' => 'Organization settings', 'actions' => ['view', 'edit']],
                'branches' => ['label' => 'Branches', 'actions' => ['view', 'create', 'edit', 'delete']],
                'users' => ['label' => 'Users', 'actions' => ['view', 'create', 'edit', 'delete']],
                'roles' => ['label' => 'Roles & permissions', 'actions' => ['view', 'edit']],
                'audit' => ['label' => 'Audit logs', 'actions' => ['view']],
                'payment_methods' => ['label' => 'Payment methods', 'actions' => ['view', 'create', 'edit', 'delete']],
                'kra_responses' => ['label' => 'KRA fiscal responses', 'actions' => ['view']],
                'till_printing' => ['label' => 'Local printing setup', 'actions' => ['view', 'edit']],
                'discount_approvals' => ['label' => 'Discount approvals', 'actions' => ['approve']],
            ],
        ],
    ],
];
