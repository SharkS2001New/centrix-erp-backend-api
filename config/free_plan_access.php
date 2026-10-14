<?php

/**
 * Package 1, the free plan. The platform administrator picks pages per industry.
 * A page that is off stays closed on the free plan. Paying for a self-serve plan
 * is separate and uses that plan's applications and sections.
 */
return [
    'name' => 'Package 1',

    'industries' => [
        'commerce' => [
            'label' => 'Retail & Distribution',
            'workspaces' => ['backoffice'],
            'default' => ['dashboard'],
            'pages' => [
                ['id' => 'dashboard', 'label' => 'Dashboard', 'section' => 'dashboard', 'exact' => ['/dashboard'], 'api' => ['reports/dashboard']],
                ['id' => 'products', 'label' => 'Products', 'section' => 'products', 'paths' => ['/products', '/categories', '/uoms', '/retail-package-settings'], 'api' => ['products', 'categories', 'sub-categories', 'uoms']],
                ['id' => 'pos', 'label' => 'Till operations', 'section' => 'pos', 'paths' => ['/sales/end-of-day', '/sales/till-management', '/sales/payments-breakdown'], 'api' => ['sales/end-of-day', 'sales/till-management', 'tills', 'till-sessions']],
                ['id' => 'pricing_tax', 'label' => 'Pricing & tax', 'section' => 'pricing_tax', 'paths' => ['/vats', '/price-history', '/reports/kra-invoices'], 'api' => ['vats', 'price-history']],
                ['id' => 'sales_orders', 'label' => 'Sales & orders', 'section' => 'sales_orders', 'exact' => ['/sales'], 'paths' => ['/sales/pos', '/sales/orders'], 'api' => ['sales', 'orders']],
                ['id' => 'shop_debtors', 'label' => 'Shop debtors', 'section' => 'shop_debtors', 'paths' => ['/sales/shop-debtors'], 'api' => ['sales/shop-debtors']],
                ['id' => 'field_sales', 'label' => 'Field sales', 'section' => 'field_sales', 'paths' => ['/sales/loading-sheets', '/sales/picking-lists', '/sales/trip-charts', '/sales/field-attendance', '/fulfillment/routes', '/fulfillment/drivers', '/fulfillment/vehicles'], 'api' => ['sales/loading-sheets', 'fulfillment']],
                ['id' => 'after_sales', 'label' => 'After sales', 'section' => 'after_sales', 'paths' => ['/sales/returns', '/sales/credit-notes', '/sales/reservations'], 'api' => ['sales/returns', 'sales/credit-notes']],
                ['id' => 'promotions', 'label' => 'Promotions & loyalty', 'section' => 'promotions', 'paths' => ['/sales/vouchers', '/sales/loyalty-cards'], 'api' => ['sales/vouchers', 'sales/loyalty-cards']],
                ['id' => 'customers', 'label' => 'Customers', 'section' => 'customers', 'paths' => ['/customers'], 'api' => ['customers']],
                ['id' => 'expenses', 'label' => 'Expenses', 'section' => 'expenses', 'paths' => ['/expenses'], 'api' => ['expenses']],
                ['id' => 'investors', 'label' => 'Investors', 'section' => 'investors', 'paths' => ['/investors'], 'api' => ['investors']],
                ['id' => 'inventory', 'label' => 'Inventory', 'section' => 'inventory', 'exact' => ['/inventory'], 'paths' => ['/inventory/stock-take', '/inventory/transactions', '/inventory/stock'], 'api' => ['inventory']],
                ['id' => 'stock_movements', 'label' => 'Stock movements', 'section' => 'stock_movements', 'paths' => ['/inventory/transfers', '/inventory/receipts', '/inventory/adjustments', '/inventory/damages'], 'api' => ['inventory/transfers', 'inventory/receipts', 'inventory/adjustments', 'inventory/damages']],
                ['id' => 'suppliers', 'label' => 'Suppliers & purchases', 'section' => 'suppliers', 'paths' => ['/suppliers', '/lpo'], 'api' => ['suppliers', 'lpo', 'purchase-orders']],
                ['id' => 'reports', 'label' => 'Reports', 'section' => 'reports', 'paths' => ['/reports'], 'api' => ['reports']],
            ],
        ],
        'hospitality' => [
            'label' => 'Hotel & Hospitality',
            'workspaces' => ['hospitality_backoffice'],
            'default' => ['dashboard'],
            'pages' => [
                ['id' => 'dashboard', 'label' => 'Dashboard', 'exact' => ['/hospitality'], 'api' => ['hospitality/dashboard', 'reports/dashboard']],
                ['id' => 'rooms', 'label' => 'Rooms & guests', 'paths' => ['/hospitality/rooms', '/hospitality/reservations', '/hospitality/front-desk', '/hospitality/folios'], 'api' => ['hospitality/rooms', 'hospitality/reservations', 'hospitality/front-desk', 'hospitality/folios']],
                ['id' => 'sales', 'label' => 'Sales', 'paths' => ['/hospitality/orders', '/hospitality/payments-breakdown', '/hospitality/end-of-day'], 'api' => ['hospitality/orders', 'hospitality/payments', 'hospitality/end-of-day']],
                ['id' => 'catalogue', 'label' => 'Menu & products', 'paths' => ['/products', '/categories', '/uoms'], 'api' => ['products', 'categories', 'uoms']],
                ['id' => 'stock', 'label' => 'Stock & receiving', 'paths' => ['/inventory'], 'api' => ['inventory']],
                ['id' => 'purchasing', 'label' => 'Purchasing', 'paths' => ['/suppliers', '/lpo'], 'api' => ['suppliers', 'lpo']],
            ],
        ],
        'spa' => [
            'label' => 'SPA & Kinyozi',
            'workspaces' => ['spa'],
            'default' => ['dashboard'],
            'pages' => [
                ['id' => 'dashboard', 'label' => 'Dashboard', 'exact' => ['/spa'], 'api' => ['spa/dashboard', 'reports/dashboard']],
                ['id' => 'appointments', 'label' => 'Appointments', 'paths' => ['/spa/appointments'], 'api' => ['spa/appointments']],
                ['id' => 'pos', 'label' => 'POS / Sales', 'paths' => ['/spa/pos'], 'api' => ['spa/pos', 'spa/sales']],
                ['id' => 'clients', 'label' => 'Clients', 'paths' => ['/spa/clients'], 'api' => ['spa/clients']],
                ['id' => 'services', 'label' => 'Services', 'paths' => ['/spa/services'], 'api' => ['spa/services']],
                ['id' => 'staff', 'label' => 'Staff & commissions', 'paths' => ['/spa/staff'], 'api' => ['spa/staff']],
                ['id' => 'inventory', 'label' => 'Inventory', 'paths' => ['/spa/inventory'], 'api' => ['spa/inventory']],
                ['id' => 'expenses', 'label' => 'Expenses', 'paths' => ['/spa/expenses'], 'api' => ['spa/expenses']],
                ['id' => 'suppliers', 'label' => 'Suppliers', 'paths' => ['/spa/suppliers'], 'api' => ['spa/suppliers']],
                ['id' => 'reports', 'label' => 'Reports', 'paths' => ['/spa/reports'], 'api' => ['spa/reports']],
            ],
        ],
        'driving' => [
            'label' => 'Driving School',
            'workspaces' => ['driving'],
            'default' => ['dashboard'],
            'pages' => [
                ['id' => 'dashboard', 'label' => 'Dashboard', 'exact' => ['/driving-school'], 'api' => ['driving-school/dashboard', 'reports/dashboard']],
                ['id' => 'schedule', 'label' => 'Appointments', 'paths' => ['/driving-school/schedule'], 'api' => ['driving-school/schedule', 'driving-school/appointments']],
                ['id' => 'students', 'label' => 'Students', 'paths' => ['/driving-school/students'], 'api' => ['driving-school/students']],
                ['id' => 'courses', 'label' => 'Courses & packages', 'paths' => ['/driving-school/courses'], 'api' => ['driving-school/courses']],
                ['id' => 'attendance', 'label' => 'Attendance', 'paths' => ['/driving-school/attendance'], 'api' => ['driving-school/attendance']],
                ['id' => 'assessments', 'label' => 'Assessments', 'paths' => ['/driving-school/assessments'], 'api' => ['driving-school/assessments']],
                ['id' => 'instructors', 'label' => 'Instructors', 'paths' => ['/driving-school/instructors'], 'api' => ['driving-school/instructors']],
                ['id' => 'vehicles', 'label' => 'Vehicles', 'paths' => ['/driving-school/vehicles'], 'api' => ['driving-school/vehicles']],
                ['id' => 'payments', 'label' => 'Payments', 'paths' => ['/driving-school/payments'], 'api' => ['driving-school/payments']],
                ['id' => 'expenses', 'label' => 'Expenses', 'paths' => ['/driving-school/expenses'], 'api' => ['driving-school/expenses']],
                ['id' => 'reports', 'label' => 'Reports', 'paths' => ['/driving-school/reports'], 'api' => ['driving-school/reports']],
            ],
        ],
    ],
];
