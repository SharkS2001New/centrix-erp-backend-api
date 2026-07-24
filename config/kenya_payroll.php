<?php

/**
 * Kenya statutory payroll rates (2026).
 * Sources: KRA PAYE bands, NSSF Act 2013 Year 4 (Feb 2026), SHIF 2.75%, Affordable Housing Levy 1.5%.
 * Update here when legislation changes — calculator reads this file only.
 */
return [
    'effective_label' => '2026-02',

    'paye' => [
        'personal_relief_monthly' => 2400,
        // Private life/health/education premiums only (not SHIF — SHIF is already a taxable-income deduction).
        'insurance_relief_rate' => 0.15,
        'insurance_relief_cap_monthly' => 5000,
        'bands' => [
            ['up_to' => 24000, 'rate' => 0.10],
            ['up_to' => 32333, 'rate' => 0.25],
            ['up_to' => 500000, 'rate' => 0.30],
            ['up_to' => 800000, 'rate' => 0.325],
            ['up_to' => null, 'rate' => 0.35],
        ],
    ],

    'nssf' => [
        'rate' => 0.06,
        'tier1_upper' => 9000,
        'tier2_upper' => 108000,
    ],

    'shif' => [
        'rate' => 0.0275,
        'minimum_monthly' => 300,
    ],

    'housing_levy' => [
        'employee_rate' => 0.015,
        'employer_rate' => 0.015,
    ],
];
