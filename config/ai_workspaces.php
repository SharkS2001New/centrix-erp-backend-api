<?php

/**
 * AI assistant scope per product workspace (mirrors config/erp_workspaces.php + web nav).
 * When the user opens a workspace, the assistant only answers for that module.
 */
return [
    'pos' => [
        'label' => 'External POS',
        'description' => 'Cashier checkout, till sessions, held orders, and receipts.',
        'nav_section_ids' => ['sales'],
        'nav_path_prefixes' => ['/pos'],
        'module_catalog_keys' => ['sales'],
        'action_types' => ['create_sales_order', 'create_held_order'],
        'workflow_keys' => ['create_sales_order', 'create_held_order'],
        'keywords' => [
            'pos', 'checkout', 'cart', 'till', 'float', 'held', 'cashier', 'receipt', 'sale', 'order',
            'payment', 'mpesa', 'voucher', 'customer', 'product', 'price', 'barcode', 'scan',
        ],
        'foreign_signals' => [
            '/\b(payroll|nssf|paye|leave request|attendance|department|shift)\b/i',
            '/\b(journal entry|chart of accounts|ledger|expense group|fiscal period)\b/i',
            '/\b(user role|permission|admin settings|register organization)\b/i',
            '/\b(lpo|supplier payment|purchase order|grn|stock take)\b/i',
        ],
    ],
    'backoffice' => [
        'label' => 'Backoffice',
        'description' => 'Sales, catalog, inventory, purchasing, logistics, and operational reports.',
        'nav_section_ids' => ['dashboard', 'sales', 'inventory', 'purchases', 'logistics', 'reports'],
        'nav_path_prefixes' => [
            '/dashboard', '/sales', '/inventory', '/products', '/categories', '/sub-categories',
            '/uoms', '/vats', '/price-history', '/customers', '/suppliers', '/lpo', '/purchases',
            '/fulfillment', '/routes', '/till-management', '/reports',
        ],
        'report_exclude_paths' => ['/reports/payroll-summary'],
        'module_catalog_keys' => ['catalogue', 'sales', 'inventory', 'purchasing', 'fulfillment', 'reports'],
        'action_types' => [
            'create_sales_order', 'create_held_order', 'create_product', 'create_report_template', 'record_customer_payment',
        ],
        'workflow_keys' => [
            'create_product', 'create_sales_order', 'create_held_order', 'record_customer_payment', 'create_report_template',
        ],
        'keywords' => [
            'product', 'catalog', 'category', 'stock', 'inventory', 'sales', 'order', 'customer', 'supplier', 'lpo',
            'purchase', 'grn', 'receipt', 'transfer', 'dispatch', 'route', 'driver', 'vehicle', 'fulfillment',
            'report', 'dashboard', 'voucher', 'credit note', 'return', 'debtor', 'receivable', 'payment',
        ],
        'foreign_signals' => [
            '/\b(payroll run|nssf|paye|leave balance|attendance sheet|kpi score)\b/i',
            '/\b(journal entry|post journal|chart of accounts|trial balance)\b/i',
            '/\b(create user|role permission|platform admin)\b/i',
        ],
    ],
    'admin' => [
        'label' => 'Administration',
        'description' => 'Users, roles, company settings, branches, and system configuration.',
        'nav_section_ids' => ['settings'],
        'nav_path_prefixes' => ['/admin', '/platform'],
        'module_catalog_keys' => ['admin'],
        'action_types' => [],
        'workflow_keys' => [],
        'keywords' => [
            'admin', 'user', 'role', 'permission', 'settings', 'branch', 'organization', 'module', 'ai assistant',
            'company profile', 'sales settings', 'distribution', 'platform',
        ],
        'foreign_signals' => [
            '/\b(create sales order|checkout|pos cart|held order)\b/i',
            '/\b(stock take|grn|low stock|reorder point)\b/i',
            '/\b(payroll|employee hire|leave request)\b/i',
            '/\b(journal entry|record payment to customer)\b/i',
        ],
    ],
    'accounting' => [
        'label' => 'Accounting',
        'description' => 'General ledger, expenses, receivables, payments, and financial reports.',
        'nav_section_ids' => ['accounting', 'reports'],
        'nav_path_prefixes' => ['/accounting', '/expenses', '/finance', '/reports'],
        'report_include_paths' => [
            '/reports', '/reports/builder', '/reports/customer-statement',
            '/reports/sales-summary', '/reports/profit-loss', '/reports/ar-aging',
            '/reports/top-debtors', '/reports/stock-on-hand',
        ],
        'module_catalog_keys' => ['accounting', 'payments', 'reports'],
        'action_types' => ['record_customer_payment', 'create_report_template'],
        'workflow_keys' => ['record_customer_payment', 'create_report_template'],
        'keywords' => [
            'account', 'accounting', 'journal', 'ledger', 'expense', 'receivable', 'payable', 'payment',
            'invoice', 'debtor', 'balance', 'fiscal', 'vat', 'report', 'statement', 'credit', 'partial payment',
        ],
        'foreign_signals' => [
            '/\b(create product|add product|catalog|subcategory|uom)\b/i',
            '/\b(payroll|employee|attendance|leave)\b/i',
            '/\b(dispatch trip|driver route|vehicle)\b/i',
            '/\b(pos checkout|held order|till float)\b/i',
        ],
    ],
    'hr' => [
        'label' => 'Human Resources',
        'description' => 'Employees, departments, attendance, leave, payroll, and HR reports.',
        'nav_section_ids' => ['hr', 'reports'],
        'nav_path_prefixes' => ['/hr', '/employees', '/reports'],
        'report_include_paths' => ['/reports', '/reports/builder'],
        'module_catalog_keys' => ['hr_payroll', 'reports'],
        'action_types' => ['create_employee', 'create_report_template'],
        'workflow_keys' => ['create_employee', 'create_report_template'],
        'keywords' => [
            'employee', 'hr', 'payroll', 'department', 'shift', 'attendance', 'leave', 'kpi', 'hire', 'staff',
            'salary', 'deduction', 'nssf', 'paye', 'report',
        ],
        'foreign_signals' => [
            '/\b(create product|inventory stock|grn|lpo)\b/i',
            '/\b(journal entry|chart of accounts|expense group)\b/i',
            '/\b(pos checkout|sales cart)\b/i',
            '/\b(admin user|role permission)\b/i',
        ],
    ],
];
