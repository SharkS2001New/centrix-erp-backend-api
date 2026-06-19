<?php

/**
 * Auto-derived and explicit join paths between report-builder data sources.
 * Used when mixing columns from multiple sources in one row-level report.
 */
return [
    /*
     * Extra edges not expressed in a source's own joins config.
     * Reverse edges are generated automatically from the target source table.
     */
    'extra_edges' => [
        [
            'from' => 'sales',
            'to' => 'sale_items',
            'join_key' => 'sales_sale_items',
            'table' => 'sale_items as si',
            'first' => 'si.sale_id',
            'op' => '=',
            'second' => 's.id',
            'left' => false,
        ],
        [
            'from' => 'sale_items',
            'to' => 'products',
            'join_key' => 'sale_items_products',
            'table' => 'products as p',
            'first' => 'p.product_code',
            'op' => '=',
            'second' => 'si.product_code',
            'left' => false,
        ],
    ],
];
