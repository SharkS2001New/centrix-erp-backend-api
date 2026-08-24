<?php

/**
 * ERP module catalog and workflows for the AI assistant.
 * Derived from routes, permission registry, and feature tests.
 */
return [
    'how_to_guide' => [
        'Centrix is organized by workspaces (top bar): Backoffice, POS, Accounting, HR, Distribution/Admin depending on your org.',
        'If you do not know where a feature lives, ask "Where is …?" — the assistant should reply with a path like /suppliers.',
        'Purchasing: Suppliers at /suppliers, purchase orders (LPO) at /lpo, receive goods (GRN) at /inventory/receipts, pay suppliers at /suppliers/payments.',
        'Inventory: Current stock at /inventory/stock, adjustments at /inventory/adjustments, stock take at /inventory/stock-take, products at /products.',
        'Sales: Orders at /sales/orders, POS at /sales/pos or /pos, debtors under /sales/shop-debtors/*, Sales by User report at /reports (Sales by user).',
        'Accounting: Chart of accounts, journals, bank reconciliation, expenses, and AR under /accounting/*.',
        'HR: Employees, today\'s attendance (/hr/attendance), previous attendance (/hr/attendance/history), leave, and payroll under /hr/*. Field attendance for mobile reps: /sales/field-attendance.',
        'Admin: Users and roles/permissions under /admin/users and /admin/roles. Organization AI settings under Organization settings → AI.',
        'Reports hub: /reports — sales, stock, payroll, and custom report builder at /reports/builder (saved reports open at /reports/custom/{id}).',
        'Cashier sales targets/quotas are not stored as a Centrix AI metric — report actual sales with get_sales_by_cashier instead.',
        'When talking about people, use username and full name — never numeric user ids.',
        'Write formulas in plain language with real field names (Stock Value = Cost Price × Stock on Hand), never LaTeX.',
    ],
    'modules' => [
        [
            'key' => 'catalogue',
            'label' => 'Product catalog',
            'paths' => ['/products', '/categories'],
            'tasks' => [
                'Create and edit products (code, name, price, VAT, reorder point)',
                'Manage categories and subcategories',
                'Register products with KRA device when fiscal module is enabled',
            ],
        ],
        [
            'key' => 'sales',
            'label' => 'Sales & POS',
            'paths' => ['/sales/pos', '/sales/orders', '/customers', '/sales/shop-debtors/unpaid'],
            'tasks' => [
                'POS checkout — create cart, add lines, pay with cash/M-Pesa/voucher',
                'Backoffice sales orders — customer + line items + checkout',
                'Held orders — save_only checkout with status held (resume or cancel later)',
                'Credit sales, vouchers, loyalty points, order discounts',
                'Shop debtors queues — unpaid / partial / paid',
            ],
        ],
        [
            'key' => 'inventory',
            'label' => 'Inventory',
            'paths' => ['/inventory/stock', '/inventory/receipts', '/inventory/stock-take', '/inventory/adjustments'],
            'tasks' => [
                'View stock on hand, low-stock alerts',
                'Receive stock from LPO (GRN), transfer between branches',
                'Stock take and adjustments',
            ],
        ],
        [
            'key' => 'purchasing',
            'label' => 'Purchasing',
            'paths' => ['/suppliers', '/lpo', '/suppliers/payments', '/inventory/receipts'],
            'tasks' => [
                'Manage suppliers and local purchase orders (LPO)',
                'Receive goods (GRN), supplier payments, supplier returns',
            ],
        ],
        [
            'key' => 'accounting',
            'label' => 'Accounting',
            'paths' => ['/accounting', '/accounting/chart-of-accounts', '/accounting/journal-entries', '/expenses'],
            'tasks' => [
                'Chart of accounts, journal entries (post/reverse via operations API)',
                'Expenses, accounts receivable/payable, fiscal period close',
                'Bank reconciliation',
            ],
        ],
        [
            'key' => 'hr_payroll',
            'label' => 'HR & payroll',
            'paths' => ['/hr/employees', '/hr/departments', '/hr/payroll', '/hr/attendance', '/hr/attendance/history', '/hr/leave', '/sales/field-attendance'],
            'tasks' => [
                'Employees, departments, shifts, attendance (today + history), leave',
                'Field attendance for mobile sales reps at /sales/field-attendance',
                'Payroll runs, deductions, organization KPIs',
            ],
        ],
        [
            'key' => 'fulfillment',
            'label' => 'Logistics & dispatch',
            'paths' => ['/fulfillment/dispatch', '/fulfillment/trips', '/fulfillment/routes', '/fulfillment/drivers'],
            'tasks' => [
                'Dispatch trips, route schedules, drivers, POD capture',
            ],
        ],
        [
            'key' => 'distribution',
            'label' => 'Distribution (fulfillment)',
            'paths' => ['/fulfillment/dispatch', '/fulfillment/trips', '/fulfillment/routes'],
            'tasks' => [
                'Same as logistics & dispatch when distribution ops are enabled for the org',
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'paths' => ['/reports', '/reports/builder', '/reports/sales-by-user', '/reports/daily-sales', '/reports/low-stock'],
            'tasks' => [
                'Built-in reports (sales, stock, payroll, KRA receipts)',
                'Sales by user / cashier performance',
                'Custom report builder — ask for a report name, save template, open /reports/custom/{id}',
            ],
        ],
        [
            'key' => 'admin',
            'label' => 'Administration',
            'paths' => ['/admin', '/admin/users', '/admin/roles', '/admin/attendance-clock'],
            'tasks' => [
                'Users, roles and permissions',
                'Organization settings (including AI credentials for org admins)',
                'Attendance clock administration',
            ],
        ],
    ],
    'workflows' => [
        'create_product' => [
            'summary' => 'Add a product to the catalog',
            'path' => '/products',
            'required' => ['product_name'],
            'optional' => ['product_code', 'unit_price', 'unit_id', 'subcategory_id', 'last_cost_price', 'reorder_point', 'vat_id'],
            'action' => 'create_product',
            'notes' => 'product_code is auto-generated as a unique 6-digit SKU when omitted. unit_id from uoms, subcategory_id from subcategories, vat_id from vats.',
        ],
        'create_sales_order' => [
            'summary' => 'Create a normal sales order (checkout with payment)',
            'path' => '/sales/orders',
            'required' => ['customer_num', 'lines' => [['product_code', 'quantity']]],
            'optional' => ['payment_method_code', 'pay_now', 'is_credit_sale', 'channel'],
            'action' => 'create_sales_order',
            'notes' => 'Default: backoffice channel, CASH payment, full amount paid. Not the same as a held order.',
        ],
        'create_held_order' => [
            'summary' => 'Save an order without payment (held / save-only)',
            'path' => '/sales/orders',
            'required' => ['customer_num', 'lines' => [['product_code', 'quantity']]],
            'optional' => ['status'],
            'action' => 'create_held_order',
        ],
        'pos_checkout' => [
            'summary' => 'Quick POS sale — cart → lines → checkout completed',
            'path' => '/sales/pos',
            'steps' => ['Create cart (channel pos)', 'Add product lines', 'Checkout with payment_method_code CASH or M-Pesa'],
            'action' => 'create_sales_order',
            'notes' => 'Use channel pos; customer_num optional for walk-in.',
        ],
        'create_employee' => [
            'summary' => 'Add HR employee record',
            'path' => '/hr/employees',
            'required' => ['first_name', 'last_name'],
            'optional' => ['department_id', 'shift_id', 'email', 'phone', 'base_salary', 'hire_date'],
            'action' => 'create_employee',
        ],
        'create_report' => [
            'summary' => 'Save a custom report template — ask for a name first, then create and share /reports/custom/{id}',
            'path' => '/reports/builder',
            'required' => ['name', 'instruction'],
            'optional' => ['spec' => ['source', 'columns', 'group_by']],
            'action' => 'create_report_template',
        ],
        'record_customer_payment' => [
            'summary' => 'Record a customer payment against an outstanding sale/invoice',
            'path' => '/accounting/accounts-receivable',
            'required' => ['sale_id', 'payment_method_id'],
            'optional' => ['amount', 'reference_number', 'mark_paid_full'],
            'action' => 'record_customer_payment',
            'notes' => 'Omit amount to pay the full balance_due. Use amount for partial payments. Requires payments.manage permission.',
        ],
        'analyze_debtors' => [
            'summary' => 'Review who owes money and open invoice balances',
            'path' => '/reports/top-debtors',
            'notes' => 'Use receivables_summary in context — top_debtors and open_invoices. No action required for read-only analysis.',
        ],
    ],
];
