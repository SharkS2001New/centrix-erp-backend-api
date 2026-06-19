<?php

/**
 * Shared row keys for blending multiple report-builder data sources.
 * Each dimension maps to a SQL expression per source; metrics are aggregated per key then joined.
 */
return [
    'day' => [
        'label' => 'Day',
        'output_alias' => 'report_day',
        'sources' => [
            'sales' => ['expr' => 'DATE(s.completed_at)', 'date_filter' => 's.completed_at'],
            'sale_items' => ['expr' => 'DATE(s.completed_at)', 'date_filter' => 's.completed_at'],
            'sale_payments' => ['expr' => 'DATE(s.completed_at)', 'date_filter' => 's.completed_at'],
            'returns' => ['expr' => 'DATE(r.created_at)', 'date_filter' => 'r.created_at'],
            'expenses' => ['expr' => 'DATE(e.expense_date)', 'date_filter' => 'e.expense_date'],
            'journal_entries' => ['expr' => 'DATE(je.entry_date)', 'date_filter' => 'je.entry_date'],
            'journal_lines' => ['expr' => 'DATE(je.entry_date)', 'date_filter' => 'je.entry_date'],
            'inventory_transactions' => ['expr' => 'DATE(it.created_at)', 'date_filter' => 'it.created_at'],
            'stock_receipts' => ['expr' => 'DATE(sr.created_at)', 'date_filter' => 'sr.created_at'],
            'stock_movements' => ['expr' => 'DATE(smh.created_at)', 'date_filter' => 'smh.created_at'],
            'damages' => ['expr' => 'DATE(d.created_at)', 'date_filter' => 'd.created_at'],
            'lpo_orders' => ['expr' => 'DATE(lpo.created_at)', 'date_filter' => 'lpo.created_at'],
            'sale_payments' => ['expr' => 'DATE(sp.paid_at)', 'date_filter' => 'sp.paid_at'],
            'employees' => ['expr' => 'DATE(emp.created_at)', 'date_filter' => 'emp.created_at'],
            'attendance' => ['expr' => 'DATE(ea.attendance_date)', 'date_filter' => 'ea.attendance_date'],
            'payroll_lines' => ['expr' => 'DATE(pr.run_date)', 'date_filter' => 'pr.run_date'],
        ],
    ],
    'month' => [
        'label' => 'Month',
        'output_alias' => 'report_month',
        'sources' => [
            'sales' => ['expr' => "DATE_FORMAT(s.completed_at, '%Y-%m')", 'date_filter' => 's.completed_at'],
            'sale_items' => ['expr' => "DATE_FORMAT(s.completed_at, '%Y-%m')", 'date_filter' => 's.completed_at'],
            'sale_payments' => ['expr' => "DATE_FORMAT(s.completed_at, '%Y-%m')", 'date_filter' => 's.completed_at'],
            'returns' => ['expr' => "DATE_FORMAT(r.created_at, '%Y-%m')", 'date_filter' => 'r.created_at'],
            'expenses' => ['expr' => "DATE_FORMAT(e.expense_date, '%Y-%m')", 'date_filter' => 'e.expense_date'],
            'journal_entries' => ['expr' => "DATE_FORMAT(je.entry_date, '%Y-%m')", 'date_filter' => 'je.entry_date'],
            'journal_lines' => ['expr' => "DATE_FORMAT(je.entry_date, '%Y-%m')", 'date_filter' => 'je.entry_date'],
            'inventory_transactions' => ['expr' => "DATE_FORMAT(it.created_at, '%Y-%m')", 'date_filter' => 'it.created_at'],
            'stock_receipts' => ['expr' => "DATE_FORMAT(sr.created_at, '%Y-%m')", 'date_filter' => 'sr.created_at'],
            'stock_movements' => ['expr' => "DATE_FORMAT(smh.created_at, '%Y-%m')", 'date_filter' => 'smh.created_at'],
            'damages' => ['expr' => "DATE_FORMAT(d.created_at, '%Y-%m')", 'date_filter' => 'd.created_at'],
            'lpo_orders' => ['expr' => "DATE_FORMAT(lpo.created_at, '%Y-%m')", 'date_filter' => 'lpo.created_at'],
            'sale_payments' => ['expr' => "DATE_FORMAT(sp.paid_at, '%Y-%m')", 'date_filter' => 'sp.paid_at'],
            'employees' => ['expr' => "DATE_FORMAT(emp.created_at, '%Y-%m')", 'date_filter' => 'emp.created_at'],
            'attendance' => ['expr' => "DATE_FORMAT(ea.attendance_date, '%Y-%m')", 'date_filter' => 'ea.attendance_date'],
            'payroll_lines' => ['expr' => "DATE_FORMAT(pr.run_date, '%Y-%m')", 'date_filter' => 'pr.run_date'],
        ],
    ],
    'branch' => [
        'label' => 'Branch',
        'output_alias' => 'branch_name',
        'sources' => [
            'sales' => ['expr' => 'b.branch_name', 'date_filter' => 's.completed_at', 'joins' => ['branches']],
            'sale_items' => ['expr' => 'b.branch_name', 'date_filter' => 's.completed_at', 'joins' => ['branches']],
            'sale_payments' => ['expr' => 'b.branch_name', 'date_filter' => 's.completed_at', 'joins' => ['branches']],
            'returns' => ['expr' => 'b.branch_name', 'date_filter' => 'r.return_date', 'joins' => ['branches']],
            'expenses' => ['expr' => 'b.branch_name', 'date_filter' => 'e.expense_date', 'joins' => ['branches']],
            'journal_entries' => ['expr' => 'b.branch_name', 'date_filter' => 'je.entry_date', 'joins' => ['branches']],
            'journal_lines' => ['expr' => 'b.branch_name', 'date_filter' => 'je.entry_date', 'joins' => ['branches']],
            'inventory_transactions' => ['expr' => 'b.branch_name', 'date_filter' => 'it.transaction_date', 'joins' => ['branches']],
            'stock_receipts' => ['expr' => 'b.branch_name', 'date_filter' => 'sr.receipt_date', 'joins' => ['branches']],
            'employees' => ['expr' => 'b.branch_name', 'date_filter' => 'emp.created_at', 'joins' => ['branches']],
            'attendance' => ['expr' => 'b.branch_name', 'date_filter' => 'ea.attendance_date', 'joins' => ['branches']],
            'payroll_lines' => ['expr' => 'b.branch_name', 'date_filter' => 'pr.run_date', 'joins' => ['branches']],
        ],
    ],
    'department' => [
        'label' => 'Department',
        'output_alias' => 'department_name',
        'sources' => [
            'employees' => ['expr' => 'dept.department_name', 'date_filter' => 'emp.created_at', 'joins' => ['departments']],
            'payroll_lines' => ['expr' => 'dept.department_name', 'date_filter' => 'pr.run_date', 'joins' => ['departments']],
        ],
    ],
];
