<?php

/**
 * Centrix Marketing Portfolio — verticals, section templates, illustration keys,
 * and product-truth knowledge used when generating prospect portfolios.
 */
return [
    'section_keys' => [
        'cover',
        'understanding',
        'fit',
        'day_in_life',
        'outcomes',
        'included',
        'next_steps',
    ],

    'section_labels' => [
        'cover' => 'Cover',
        'understanding' => 'Understanding your business',
        'fit' => 'How Centrix fits',
        'day_in_life' => 'A day with Centrix',
        'outcomes' => 'Outcomes you can expect',
        'included' => 'What is included',
        'next_steps' => 'Next steps',
    ],

    'illustrations' => [
        'hero_retail' => [
            'label' => 'Retail floor & POS',
            'hint' => 'Checkout, shelves, and daily trade',
            'gradient' => ['#4C5BA4', '#2DB88A'],
        ],
        'hero_supermarket' => [
            'label' => 'Supermarket operations',
            'hint' => 'Multi-till, stock rooms, promotions',
            'gradient' => ['#4C5BA4', '#F59E0B'],
        ],
        'hero_hotel' => [
            'label' => 'Hotel & F&B',
            'hint' => 'Front desk, rooms, restaurant',
            'gradient' => ['#14B8A6', '#4C5BA4'],
        ],
        'hero_spa' => [
            'label' => 'Spa & wellness',
            'hint' => 'Appointments, retail, memberships',
            'gradient' => ['#EC4899', '#6366F1'],
        ],
        'hero_distribution' => [
            'label' => 'Distribution & routes',
            'hint' => 'Trips, vans, field sales',
            'gradient' => ['#6366F1', '#2DB88A'],
        ],
        'ill_pos' => [
            'label' => 'Point of sale',
            'hint' => 'Fast checkout and receipts',
            'gradient' => ['#4C5BA4', '#6366F1'],
        ],
        'ill_stock' => [
            'label' => 'Stock & purchasing',
            'hint' => 'LPOs, GRN, stock on hand',
            'gradient' => ['#2DB88A', '#14B8A6'],
        ],
        'ill_finance' => [
            'label' => 'Debtors & cash',
            'hint' => 'Credit control and till health',
            'gradient' => ['#F59E0B', '#4C5BA4'],
        ],
        'ill_hr' => [
            'label' => 'People & attendance',
            'hint' => 'Shifts, lateness, payroll',
            'gradient' => ['#6366F1', '#EC4899'],
        ],
        'ill_manager' => [
            'label' => 'Manager insights',
            'hint' => 'Approvals and live reports',
            'gradient' => ['#4C5BA4', '#0F172A'],
        ],
        'ill_hotel' => [
            'label' => 'Rooms & F&B',
            'hint' => 'Occupancy and outlet sales',
            'gradient' => ['#14B8A6', '#0F172A'],
        ],
        'ill_spa' => [
            'label' => 'Bookings & retail',
            'hint' => 'Treatments, products, members',
            'gradient' => ['#EC4899', '#4C5BA4'],
        ],
    ],

    'verticals' => [
        'small_shop' => [
            'label' => 'Retail shop',
            'industry' => 'commerce',
            'hero_image_key' => 'hero_retail',
            'default_modules' => ['pos', 'inventory', 'purchasing', 'customers', 'reports'],
            'pain_hints' => ['Stock running out without warning', 'End-of-day cash not matching', 'Hard to see what sells'],
            'value_bullets' => [
                'Fast POS with receipts and M-Pesa-ready payments',
                'Stock on hand and low-stock alerts',
                'Simple purchase orders (LPOs) and goods receiving',
                'Daily sales and cashier accountability',
            ],
        ],
        'supermarket' => [
            'label' => 'Supermarket',
            'industry' => 'commerce',
            'hero_image_key' => 'hero_supermarket',
            'default_modules' => ['pos', 'inventory', 'purchasing', 'pricing', 'reports', 'hr'],
            'pain_hints' => ['Many tills hard to reconcile', 'Promotions and price changes messy', 'Shrinkage and expired stock'],
            'value_bullets' => [
                'Multi-till POS with end-of-day and till sessions',
                'Catalogue, VAT, and retail packaging',
                'Purchasing, GRN, and supplier payments',
                'Manager app for approvals and live reports',
            ],
        ],
        'wholesale_retail' => [
            'label' => 'Wholesale & retail',
            'industry' => 'commerce',
            'hero_image_key' => 'hero_retail',
            'default_modules' => ['pos', 'sales', 'inventory', 'purchasing', 'customers', 'accounting'],
            'pain_hints' => ['Credit customers hard to control', 'Mix of counter and order sales', 'Branch stock visibility'],
            'value_bullets' => [
                'Credit limits, statements, and debtors aging',
                'Backoffice sales orders plus POS',
                'Multi-branch stock and transfers',
                'Accounts receivable aligned to sales',
            ],
        ],
        'distribution' => [
            'label' => 'Distribution & routes',
            'industry' => 'commerce',
            'hero_image_key' => 'hero_distribution',
            'default_modules' => ['distribution', 'sales', 'inventory', 'mobile', 'reports'],
            'pain_hints' => ['Van loads not tracked', 'Field sales cash collections', 'Route performance unclear'],
            'value_bullets' => [
                'Dispatch trips, vehicle loads, and POD',
                'Mobile field sales with route pricing',
                'Trip cash settlement and compliance',
                'Manager visibility on routes and collections',
            ],
        ],
        'hotel_bar' => [
            'label' => 'Hotel & hospitality',
            'industry' => 'hospitality',
            'hero_image_key' => 'hero_hotel',
            'default_modules' => ['hotel', 'pos', 'inventory', 'purchasing', 'reports'],
            'pain_hints' => ['Front desk and F&B systems disconnected', 'Room charges missed', 'Kitchen stock vs outlet sales'],
            'value_bullets' => [
                'Hotel bar / F&B POS tied to guest operations',
                'Stock and purchasing for kitchens and bars',
                'Collect payment flows with clear bill / paid / due',
                'Backoffice reports for outlets and periods',
            ],
        ],
        'spa_salon' => [
            'label' => 'Spa & salon',
            'industry' => 'hospitality',
            'hero_image_key' => 'hero_spa',
            'default_modules' => ['pos', 'inventory', 'customers', 'hr', 'reports'],
            'pain_hints' => ['Appointments and product sales separate', 'Therapist productivity unclear', 'Membership / package tracking'],
            'value_bullets' => [
                'Retail POS for products and walk-in sales',
                'Customer history and credit where needed',
                'Staff attendance for therapists and front desk',
                'Sales and stock reports for treatments and retail',
            ],
        ],
    ],

    'module_catalog' => [
        'pos' => 'Point of Sale',
        'sales' => 'Sales & orders',
        'inventory' => 'Inventory & stock',
        'purchasing' => 'Purchasing & LPOs',
        'customers' => 'Customers & credit',
        'pricing' => 'Pricing & VAT',
        'accounting' => 'Accounting',
        'hr' => 'HR & attendance',
        'distribution' => 'Distribution & trips',
        'mobile' => 'Mobile field sales',
        'manager' => 'Centrix Manager app',
        'hotel' => 'Hotel & F&B',
        'reports' => 'Reports & insights',
        'ai' => 'Centrix Assistant',
    ],

    'product_truth' => <<<'TXT'
Centrix is a Kenya-focused ERP for retail, wholesale, distribution, and hospitality.
Currency is Kenyan Shillings (KES). Core pillars:
- Commerce: POS (tills, EOD, payments), catalogue, inventory, LPOs/GRN, customers/debtors, reports, Centrix Manager mobile app for approvals and dashboards, optional field/mobile sales and distribution trips.
- Hospitality: hotel / bar POS and guest-oriented collect-payment flows, stock for outlets, purchasing, backoffice reports.
- Shared: HR attendance & payroll, accounting (AR/AP, P&L), AI Centrix Assistant for natural-language questions and guided creates (e.g. LPOs) with confirm-before-save.
- Channels: Backoffice web ERP, POS terminals, Centrix Manager app, optional mobile sales app.
Do not invent modules Centrix does not have (e.g. full spa booking engine). For spa/salon, emphasise POS retail, customers, stock, HR attendance, and reports — honest fit, not overclaim.
Tone: confident, concrete, Kenya SME-aware. Prefer outcomes over jargon. Never say "backend"; say Backoffice.
TXT,
];
