<?php

/**
 * Top-level industry families for tenant provisioning.
 * Select industry first, then a setup type (deployment profile) within that industry.
 *
 * Existing organizations (pre-Hotel) map to commerce / Retail & Distribution via their
 * deployment_profile. Only hotel_bar is hospitality.
 */
return [
    'order' => [
        'commerce',
        'hospitality',
    ],

    'definitions' => [
        'commerce' => [
            'label' => 'Retail & Distribution',
            'description' => 'Shops, wholesale, supermarket, and logistics / distribution.',
            'default_profile' => 'wholesale_retail',
            'profile_keys' => [
                'small_shop',
                'wholesale_retail',
                'supermarket',
                'distribution',
                'custom',
            ],
            // Permission application IDs shown in Roles & permissions for this industry.
            'permission_application_ids' => [
                'pos',
                'mobile',
                'manager',
                'backoffice',
                'accounting',
                'hr',
                'distribution',
                'admin',
            ],
        ],
        'hospitality' => [
            'label' => 'Hotel & Hospitality',
            'description' => 'Hotels, lodges, bars, restaurants, and guest operations.',
            'default_profile' => 'hotel_bar',
            'profile_keys' => [
                'hotel_bar',
            ],
            'permission_application_ids' => [
                'hotel_bar_pos',
                'hospitality_backoffice',
                // Shared finance/HR/admin when those apps are later enabled for a hotel tenant.
                'accounting',
                'hr',
                'admin',
            ],
        ],
    ],
];
