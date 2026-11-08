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
        'spa',
        'driving',
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
                // Centrix Manager (approvals / reports) when hotel ops enable the manager channel.
                'manager',
                // Shared finance/HR/admin when those apps are later enabled for a hotel tenant.
                'accounting',
                'hr',
                'admin',
            ],
        ],
        'spa' => [
            'label' => 'SPA & Kinyozi',
            'description' => 'Salons, spas, and barbershops. Appointments, treatments, chairs, and walk-in sales.',
            'default_profile' => 'spa_kinyozi',
            'profile_keys' => [
                'spa_kinyozi',
            ],
            'permission_application_ids' => [
                'spa',
                'admin',
            ],
        ],
        'driving' => [
            'label' => 'Driving School',
            'description' => 'Student enrolment, lessons, instructors, vehicles, fees, and school reports.',
            'default_profile' => 'driving_school',
            'profile_keys' => [
                'driving_school',
            ],
            'permission_application_ids' => [
                'driving',
                'admin',
            ],
        ],
    ],
];
