<?php

/**
 * ERP module catalog and workflows for the AI assistant.
 * Derived from routes, permission registry, and feature tests.
 */
return [
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
            'paths' => ['/sales/pos', '/sales/orders', '/customers'],
            'tasks' => [
                'POS checkout — create cart, add lines, pay with cash/M-Pesa/voucher',
                'Backoffice sales orders — customer + line items + checkout',
                'Held orders — save_only checkout with status held (resume or cancel later)',
                'Credit sales, vouchers, loyalty points, order discounts',
            ],
        ],
        [
            'key' => 'inventory',
            'label' => 'Inventory',
            'paths' => ['/inventory/stock', '/inventory/receipts', '/inventory/stock-take'],
            'tasks' => [
                'View stock on hand, low-stock alerts',
                'Receive stock from LPO (GRN), transfer between branches',
                'Stock take and adjustments',
            ],
        ],
        [
            'key' => 'purchasing',
            'label' => 'Purchasing',
            'paths' => ['/suppliers', '/lpo', '/suppliers/payments'],
            'tasks' => [
                'Manage suppliers and local purchase orders (LPO)',
                'Receive goods, supplier payments, supplier returns',
            ],
        ],
        [
            'key' => 'accounting',
            'label' => 'Accounting',
            'paths' => ['/accounting/chart-of-accounts', '/accounting/journal-entries', '/expenses'],
            'tasks' => [
                'Chart of accounts, journal entries (post/reverse via operations API)',
                'Expenses, accounts receivable/payable, fiscal period close',
            ],
        ],
        [
            'key' => 'hr_payroll',
            'label' => 'HR & payroll',
            'paths' => ['/hr/employees', '/hr/departments', '/hr/payroll', '/hr/kpis'],
            'tasks' => [
                'Employees, departments, shifts, attendance, leave',
                'Payroll runs, deductions, organization KPIs',
            ],
        ],
        [
            'key' => 'fulfillment',
            'label' => 'Logistics & dispatch',
            'paths' => ['/fulfillment/dispatch', '/fulfillment/trips', '/fulfillment/routes'],
            'tasks' => [
                'Dispatch trips, route schedules, drivers, POD capture',
            ],
        ],
        [
            'key' => 'reports',
            'label' => 'Reports',
            'paths' => ['/reports', '/reports/builder'],
            'tasks' => [
                'Built-in reports (sales, stock, payroll, KRA receipts)',
                'Custom report builder — any module/source, unlimited columns',
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
            'notes' => 'product_code is auto-generated (PRD#0001) when omitted. unit_id from uoms, subcategory_id from subcategories, vat_id from vats.',
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
            'summary' => 'Save a custom report template',
            'path' => '/reports/builder',
            'required' => ['name', 'spec' => ['source', 'columns', 'group_by']],
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
