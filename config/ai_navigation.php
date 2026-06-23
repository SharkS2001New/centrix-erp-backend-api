<?php

/**
 * ERP navigation map for the AI assistant (mirrors web sidebar structure).
 * Each item may require a permission code; visibility is filtered per user at runtime.
 */
return [
    'sections' => [
        [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'items' => [
                ['label' => 'Overview', 'path' => '/dashboard', 'permission' => 'dashboard.overview.view'],
                ['label' => 'Sales dashboard', 'path' => '/sales', 'module' => 'sales.backend', 'permission' => 'sales.dashboard.view'],
                ['label' => 'Inventory dashboard', 'path' => '/inventory', 'module' => 'inventory', 'permission' => 'inventory.stock.view'],
                ['label' => 'Fulfillment dashboard', 'path' => '/fulfillment', 'module' => 'customers_suppliers', 'permission' => 'fulfillment.drivers.view', 'requires_distribution_ops' => true],
                ['label' => 'Report overview', 'path' => '/reports', 'module' => 'reports', 'permission' => 'reports.hub.view'],
            ],
        ],
        [
            'id' => 'sales',
            'label' => 'Sales',
            'items' => [
                ['label' => 'Create order', 'path' => '/sales/pos', 'module' => 'sales.pos', 'permission' => 'pos.checkout.create'],
                ['label' => 'Cashier terminal', 'path' => '/pos', 'module' => 'sales.pos', 'permission' => 'pos.terminal.view'],
                ['label' => 'Till management', 'path' => '/sales/till-management', 'module' => 'sales.pos', 'permission' => 'pos.till_management.view'],
                ['label' => 'Sales orders', 'path' => '/sales/orders', 'module' => 'sales.backend', 'permission' => 'sales.orders.view'],
                ['label' => 'Customers', 'path' => '/customers', 'module' => 'customers_suppliers', 'permission' => 'customers.customers.view'],
                ['label' => 'Vouchers', 'path' => '/sales/vouchers', 'module' => 'sales.backend', 'permission' => 'sales.vouchers.view'],
                ['label' => 'Credit notes', 'path' => '/sales/returns', 'module' => 'sales.backend', 'permission' => 'sales.returns.view'],
            ],
        ],
        [
            'id' => 'inventory',
            'label' => 'Inventory & catalog',
            'items' => [
                ['label' => 'Products', 'path' => '/products', 'permission' => 'catalogue.products.view'],
                ['label' => 'Categories', 'path' => '/categories', 'permission' => 'catalogue.categories.view'],
                ['label' => 'Current stock', 'path' => '/inventory/stock', 'module' => 'inventory', 'permission' => 'inventory.stock.view'],
                ['label' => 'Stock receipts (GRN)', 'path' => '/inventory/receipts', 'module' => 'inventory', 'permission' => 'inventory.receipts.view'],
                ['label' => 'Stock adjustments', 'path' => '/inventory/adjustments', 'module' => 'inventory', 'permission' => 'inventory.adjustments.view'],
                ['label' => 'Stock take', 'path' => '/inventory/stock-take', 'module' => 'inventory', 'permission' => 'inventory.stock_take.view'],
            ],
        ],
        [
            'id' => 'purchases',
            'label' => 'Purchasing',
            'items' => [
                ['label' => 'Suppliers', 'path' => '/suppliers', 'module' => 'customers_suppliers', 'permission' => 'purchasing.suppliers.view'],
                ['label' => 'Purchase orders (LPO)', 'path' => '/lpo', 'module' => 'customers_suppliers', 'permission' => 'purchasing.lpo.view'],
                ['label' => 'Supplier payments', 'path' => '/suppliers/payments', 'module' => 'customers_suppliers', 'permission' => 'purchasing.supplier_payments.view'],
            ],
        ],
        [
            'id' => 'accounting',
            'label' => 'Accounting',
            'items' => [
                ['label' => 'Finance overview', 'path' => '/accounting', 'module' => 'accounting', 'permission' => 'accounting.dashboard.view'],
                ['label' => 'Chart of accounts', 'path' => '/accounting/chart-of-accounts', 'module' => 'accounting', 'permission' => 'accounting.chart_of_accounts.view'],
                ['label' => 'Journal entries', 'path' => '/accounting/journal-entries', 'module' => 'accounting', 'permission' => 'accounting.journal_entries.view'],
                ['label' => 'Expenses', 'path' => '/expenses', 'module' => 'accounting', 'permission' => 'accounting.expenses.view'],
                ['label' => 'Accounts receivable', 'path' => '/accounting/accounts-receivable', 'module' => 'accounting', 'permission' => 'accounting.accounts_receivable.view'],
            ],
        ],
        [
            'id' => 'hr',
            'label' => 'Human resources',
            'items' => [
                ['label' => 'HR Overview', 'path' => '/hr', 'module' => 'hr_payroll', 'permission' => 'hr.employees.view'],
                ['label' => 'Employees', 'path' => '/hr/employees', 'module' => 'hr_payroll', 'permission' => 'hr.employees.view'],
                ['label' => 'Departments', 'path' => '/hr/departments', 'module' => 'hr_payroll', 'permission' => 'hr.departments.view'],
                ['label' => 'Attendance', 'path' => '/hr/attendance', 'module' => 'hr_payroll', 'permission' => 'hr.attendance.view'],
                ['label' => 'Leave', 'path' => '/hr/leave', 'module' => 'hr_payroll', 'permission' => 'hr.leave.view'],
                ['label' => 'Payroll', 'path' => '/hr/payroll', 'module' => 'hr_payroll', 'permission' => 'hr.payroll.view'],
            ],
        ],
        [
            'id' => 'logistics',
            'label' => 'Fulfillment',
            'requires_distribution_ops' => true,
            'items' => [
                ['label' => 'Dispatch', 'path' => '/fulfillment/dispatch', 'module' => 'customers_suppliers', 'permission' => 'fulfillment.drivers.view'],
                ['label' => 'Trips / shipment tracking', 'path' => '/fulfillment/trips', 'module' => 'customers_suppliers', 'permission' => 'fulfillment.drivers.view'],
                ['label' => 'Drivers', 'path' => '/fulfillment/drivers', 'module' => 'customers_suppliers', 'permission' => 'fulfillment.drivers.view'],
                ['label' => 'Routes', 'path' => '/fulfillment/routes', 'module' => 'customers_suppliers', 'permission' => 'fulfillment.routes.view'],
            ],
        ],
        [
            'id' => 'reports',
            'label' => 'Reports',
            'module' => 'reports',
            'items' => [
                ['label' => 'Report overview', 'path' => '/reports', 'module' => 'reports', 'permission' => 'reports.hub.view'],
                ['label' => 'Report builder', 'path' => '/reports/builder', 'module' => 'reports', 'permission' => 'reports.builder.view'],
                ['label' => 'Sales summary', 'path' => '/reports/sales-summary', 'module' => 'reports', 'permission' => 'reports.sales_summary.view'],
                ['label' => 'Stock on hand', 'path' => '/reports/stock-on-hand', 'module' => 'reports', 'permission' => 'reports.stock_on_hand.view'],
                ['label' => 'Payroll summary', 'path' => '/reports/payroll-summary', 'module' => 'hr_payroll.reports', 'permission' => 'hr.payroll.view'],
                ['label' => 'Leave balance', 'path' => '/reports/leave-balance', 'module' => 'hr_payroll.reports', 'permission' => 'hr.leave.view'],
                ['label' => 'Statutory deductions', 'path' => '/reports/statutory-deductions', 'module' => 'hr_payroll.reports', 'permission' => 'hr.payroll.view'],
                ['label' => 'Bank transfer', 'path' => '/reports/bank-transfer', 'module' => 'hr_payroll.reports', 'permission' => 'hr.payroll.view'],
                ['label' => 'Headcount', 'path' => '/reports/headcount', 'module' => 'hr_payroll.reports', 'permission' => 'hr.employees.view'],
                ['label' => 'Contract expiry', 'path' => '/reports/contract-expiry', 'module' => 'hr_payroll.reports', 'permission' => 'hr.employees.view'],
                ['label' => 'Staff turnover', 'path' => '/reports/staff-turnover', 'module' => 'hr_payroll.reports', 'permission' => 'hr.employees.view'],
                ['label' => 'Workforce summary', 'path' => '/reports/hr-dashboard-kpi', 'module' => 'hr_payroll.reports', 'permission' => 'hr.employees.view'],
            ],
        ],
        [
            'id' => 'settings',
            'label' => 'Administration & settings',
            'items' => [
                ['label' => 'Admin home', 'path' => '/admin', 'module' => 'admin', 'permission' => 'admin.overview.view'],
                ['label' => 'Users', 'path' => '/admin/users', 'module' => 'admin', 'permission' => 'admin.users.view', 'requires_admin' => true],
                ['label' => 'Roles and permissions', 'path' => '/admin/roles', 'module' => 'admin', 'permission' => 'admin.roles.view'],
            ],
        ],
    ],
    'actions' => [
        [
            'type' => 'create_sales_order',
            'label' => 'Create a sales order (checkout with payment)',
            'description' => 'Normal order — customer, line items, checkout with payment. Use create_held_order only when user explicitly wants to hold/save without payment.',
            'permission' => 'sales.orders.create',
            'module' => 'sales.backend',
        ],
        [
            'type' => 'create_held_order',
            'label' => 'Save a held order (no payment yet)',
            'description' => 'Save-only checkout — use only when the user asks to hold, save for later, or defer payment.',
            'permission' => 'sales.orders.create',
            'module' => 'sales.backend',
        ],
        [
            'type' => 'create_product',
            'label' => 'Create a product',
            'description' => 'Add a new product to the catalog (code, name, price, etc.).',
            'permission' => 'catalogue.products.create',
        ],
        [
            'type' => 'create_supplier',
            'label' => 'Create a supplier',
            'description' => 'Add a new supplier for purchases and accounts payable.',
            'permission' => 'purchasing.suppliers.create',
            'module' => 'customers_suppliers',
        ],
        [
            'type' => 'create_customer',
            'label' => 'Create a customer',
            'description' => 'Add a new customer for sales and receivables.',
            'permission' => 'customers.customers.create',
            'module' => 'customers_suppliers',
        ],
        [
            'type' => 'create_employee',
            'label' => 'Create an employee',
            'description' => 'Add a new employee record in HR.',
            'permission' => 'hr.employees.create',
            'module' => 'hr_payroll',
        ],
        [
            'type' => 'create_report_template',
            'label' => 'Create a custom report',
            'description' => 'Save a report builder template.',
            'permission' => 'reports.builder.create',
            'module' => 'reports',
        ],
        [
            'type' => 'record_customer_payment',
            'label' => 'Record a customer payment (full or partial)',
            'description' => 'Apply payment to an outstanding sale/invoice — full balance or a partial amount.',
            'permission' => 'payments.manage',
            'module' => 'payments',
        ],
    ],
];
