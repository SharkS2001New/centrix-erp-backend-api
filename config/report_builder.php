<?php

/**
 * Allowlisted data sources for the report builder.
 * Users compose reports from these fields only — no raw SQL.
 */
return [
    'sources' => [
        'sales' => [
            'label' => 'Sales',
            'description' => 'Completed sales orders',
            'table' => 'sales as s',
            'org_column' => 's.organization_id',
            'branch_column' => 's.branch_id',
            'default_date_column' => 's.completed_at',
            'base_where' => [
                ['s.status', '=', 'completed'],
                ['s.archived', '=', 0],
            ],
            'joins' => [
                'branches' => ['branches as b', 'b.id', '=', 's.branch_id'],
                'customers' => ['customers as c', 'c.customer_num', '=', 's.customer_num'],
            ],
            'fields' => [
                'sale_day' => [
                    'label' => 'Sale date',
                    'expr' => 'DATE(s.completed_at)',
                    'type' => 'date',
                    'groupable' => true,
                ],
                'order_num' => [
                    'label' => 'Order #',
                    'expr' => 's.order_num',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'branch_name' => [
                    'label' => 'Branch',
                    'expr' => 'b.branch_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'branches',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'expr' => 's.channel',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'customer_name' => [
                    'label' => 'Customer',
                    'expr' => "COALESCE(c.customer_name, s.customer_name_override)",
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'customers',
                ],
                'payment_status' => [
                    'label' => 'Payment status',
                    'expr' => 's.payment_status',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'order_total' => [
                    'label' => 'Order total',
                    'expr' => 's.order_total',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg', 'count', 'min', 'max'],
                ],
                'amount_paid' => [
                    'label' => 'Amount paid',
                    'expr' => 's.amount_paid',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg'],
                ],
                'total_vat' => [
                    'label' => 'VAT',
                    'expr' => 's.total_vat',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg'],
                ],
                'order_count' => [
                    'label' => 'Orders',
                    'expr' => 's.id',
                    'type' => 'number',
                    'aggregates' => ['count'],
                ],
            ],
        ],

        'sale_items' => [
            'label' => 'Sale line items',
            'description' => 'Products sold on completed orders',
            'table' => 'sale_items as si',
            'org_column' => 's.organization_id',
            'branch_column' => 's.branch_id',
            'default_date_column' => 's.completed_at',
            'base_where' => [
                ['s.status', '=', 'completed'],
                ['s.archived', '=', 0],
            ],
            'joins' => [
                'sales' => ['sales as s', 's.id', '=', 'si.sale_id'],
                'products' => ['products as p', 'p.product_code', '=', 'si.product_code'],
                'branches' => ['branches as b', 'b.id', '=', 's.branch_id'],
            ],
            'fields' => [
                'sale_day' => [
                    'label' => 'Sale date',
                    'expr' => 'DATE(s.completed_at)',
                    'type' => 'date',
                    'groupable' => true,
                    'requires_join' => 'sales',
                ],
                'product_code' => [
                    'label' => 'Product code',
                    'expr' => 'si.product_code',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'product_name' => [
                    'label' => 'Product name',
                    'expr' => 'p.product_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'products',
                ],
                'branch_name' => [
                    'label' => 'Branch',
                    'expr' => 'b.branch_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'branches',
                ],
                'channel' => [
                    'label' => 'Channel',
                    'expr' => 's.channel',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'sales',
                ],
                'quantity' => [
                    'label' => 'Quantity sold',
                    'expr' => 'si.quantity',
                    'type' => 'number',
                    'aggregates' => ['sum', 'avg', 'count'],
                ],
                'line_revenue' => [
                    'label' => 'Line revenue',
                    'expr' => 'si.amount',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg'],
                ],
                'line_vat' => [
                    'label' => 'Line VAT',
                    'expr' => 'si.product_vat',
                    'type' => 'money',
                    'aggregates' => ['sum'],
                ],
                'line_discount' => [
                    'label' => 'Discount',
                    'expr' => 'si.discount_given',
                    'type' => 'money',
                    'aggregates' => ['sum'],
                ],
            ],
        ],

        'customers' => [
            'label' => 'Customers',
            'description' => 'Customer master and balances',
            'table' => 'customers as c',
            'org_column' => 'c.organization_id',
            'branch_column' => 'c.branch_id',
            'default_date_column' => 'c.created_at',
            'base_where' => [
                ['c.deleted_at', '=', null],
            ],
            'joins' => [
                'routes' => ['routes as r', 'r.id', '=', 'c.route_id'],
                'branches' => ['branches as b', 'b.id', '=', 'c.branch_id'],
            ],
            'fields' => [
                'customer_num' => [
                    'label' => 'Customer code',
                    'expr' => 'c.customer_num',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'customer_name' => [
                    'label' => 'Customer name',
                    'expr' => 'c.customer_name',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'customer_type' => [
                    'label' => 'Type',
                    'expr' => 'c.customer_type',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'branch_name' => [
                    'label' => 'Branch',
                    'expr' => 'b.branch_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'branches',
                ],
                'route_name' => [
                    'label' => 'Route',
                    'expr' => 'r.route_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'routes',
                ],
                'current_balance' => [
                    'label' => 'Current balance',
                    'expr' => 'c.current_balance',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg', 'max'],
                ],
                'credit_limit' => [
                    'label' => 'Credit limit',
                    'expr' => 'c.credit_limit',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg'],
                ],
                'customer_count' => [
                    'label' => 'Customers',
                    'expr' => 'c.customer_num',
                    'type' => 'number',
                    'aggregates' => ['count'],
                ],
            ],
        ],

        'stock' => [
            'label' => 'Stock on hand',
            'description' => 'Current inventory levels and valuation',
            'table' => 'current_stock as cs',
            'org_column' => 'p.organization_id',
            'branch_column' => 'cs.branch_id',
            'default_date_column' => null,
            'base_where' => [
                ['p.deleted_at', '=', null],
            ],
            'joins' => [
                'products' => ['products as p', 'p.product_code', '=', 'cs.product_code'],
                'branches' => ['branches as b', 'b.id', '=', 'cs.branch_id'],
            ],
            'fields' => [
                'product_code' => [
                    'label' => 'Product code',
                    'expr' => 'cs.product_code',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'product_name' => [
                    'label' => 'Product name',
                    'expr' => 'p.product_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'products',
                ],
                'branch_name' => [
                    'label' => 'Branch',
                    'expr' => 'b.branch_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'branches',
                ],
                'shop_quantity' => [
                    'label' => 'Shop qty',
                    'expr' => 'cs.shop_quantity',
                    'type' => 'number',
                    'aggregates' => ['sum', 'avg'],
                ],
                'store_quantity' => [
                    'label' => 'Store qty',
                    'expr' => 'cs.store_quantity',
                    'type' => 'number',
                    'aggregates' => ['sum', 'avg'],
                ],
                'total_qty' => [
                    'label' => 'Total qty',
                    'expr' => '(cs.shop_quantity + cs.store_quantity)',
                    'type' => 'number',
                    'aggregates' => ['sum', 'avg'],
                ],
                'cost_value' => [
                    'label' => 'Cost value',
                    'expr' => '(cs.shop_quantity + cs.store_quantity) * COALESCE(p.last_cost_price, 0)',
                    'type' => 'money',
                    'aggregates' => ['sum'],
                    'requires_join' => 'products',
                ],
                'retail_value' => [
                    'label' => 'Retail value',
                    'expr' => '(cs.shop_quantity + cs.store_quantity) * p.unit_price',
                    'type' => 'money',
                    'aggregates' => ['sum'],
                    'requires_join' => 'products',
                ],
                'sku_count' => [
                    'label' => 'SKUs',
                    'expr' => 'cs.product_code',
                    'type' => 'number',
                    'aggregates' => ['count'],
                ],
            ],
        ],

        'invoices' => [
            'label' => 'Customer invoices',
            'description' => 'Invoices and balances',
            'table' => 'customer_invoices as ci',
            'org_column' => 'ci.organization_id',
            'branch_column' => 'ci.branch_id',
            'default_date_column' => 'ci.invoice_date',
            'base_where' => [
                ['ci.deleted_at', '=', null],
            ],
            'joins' => [
                'customers' => ['customers as c', 'c.customer_num', '=', 'ci.customer_num'],
                'branches' => ['branches as b', 'b.id', '=', 'ci.branch_id'],
            ],
            'fields' => [
                'invoice_date' => [
                    'label' => 'Invoice date',
                    'expr' => 'ci.invoice_date',
                    'type' => 'date',
                    'groupable' => true,
                ],
                'invoice_number' => [
                    'label' => 'Invoice #',
                    'expr' => 'ci.invoice_number',
                    'type' => 'string',
                    'groupable' => true,
                ],
                'customer_name' => [
                    'label' => 'Customer',
                    'expr' => 'c.customer_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'customers',
                ],
                'branch_name' => [
                    'label' => 'Branch',
                    'expr' => 'b.branch_name',
                    'type' => 'string',
                    'groupable' => true,
                    'requires_join' => 'branches',
                ],
                'invoice_total' => [
                    'label' => 'Invoice total',
                    'expr' => 'ci.invoice_total',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg'],
                ],
                'amount_paid' => [
                    'label' => 'Amount paid',
                    'expr' => 'ci.amount_paid',
                    'type' => 'money',
                    'aggregates' => ['sum'],
                ],
                'balance_due' => [
                    'label' => 'Balance due',
                    'expr' => 'ci.balance_due',
                    'type' => 'money',
                    'aggregates' => ['sum', 'avg', 'max'],
                ],
                'invoice_count' => [
                    'label' => 'Invoices',
                    'expr' => 'ci.id',
                    'type' => 'number',
                    'aggregates' => ['count'],
                ],
            ],
        ],
    ],

    'aggregates' => ['sum', 'avg', 'count', 'min', 'max'],
    'chart_types' => ['bar', 'donut'],
    'max_columns' => 12,
    'max_group_by' => 4,
];
