<?php

namespace App\Services\Payroll;

class KenyaStatutoryReference
{
    /**
     * Human-readable formulas and config for UI (read-only).
     *
     * @return array<string, mixed>
     */
    public static function describe(): array
    {
        $cfg = config('kenya_payroll');
        $nssf = $cfg['nssf'];
        $shif = $cfg['shif'];
        $hl = $cfg['housing_levy'];
        $paye = $cfg['paye'];

        $bands = array_map(function ($b) {
            $cap = $b['up_to'] === null ? 'above' : 'KES '.number_format((float) $b['up_to']);
            $pct = round((float) $b['rate'] * 100, 2);

            return ['up_to_label' => $cap, 'rate_percent' => $pct];
        }, $paye['bands']);

        return [
            'effective_label' => $cfg['effective_label'] ?? '2026',
            'items' => [
                [
                    'id' => 'nssf',
                    'label' => 'NSSF',
                    'formula' => sprintf(
                        'Tier I: 6%% × min(gross, KES %s). Tier II: 6%% × max(0, min(gross, KES %s) − KES %s). Employee + employer each pay the same NSSF amount.',
                        number_format($nssf['tier1_upper']),
                        number_format($nssf['tier2_upper']),
                        number_format($nssf['tier1_upper']),
                    ),
                    'rates' => [
                        'employee_rate' => '6% on pensionable earnings (two tiers)',
                        'tier1_upper' => $nssf['tier1_upper'],
                        'tier2_upper' => $nssf['tier2_upper'],
                    ],
                ],
                [
                    'id' => 'shif',
                    'label' => 'SHIF (NHIF successor)',
                    'formula' => sprintf(
                        'max(KES %s, gross × %s%%). Deducted before PAYE; SHIF also caps insurance relief on PAYE at KES %s/month.',
                        number_format($shif['minimum_monthly']),
                        round($shif['rate'] * 100, 2),
                        number_format($paye['insurance_relief_cap_monthly']),
                    ),
                    'rates' => [
                        'rate_percent' => round($shif['rate'] * 100, 2),
                        'minimum_monthly' => $shif['minimum_monthly'],
                    ],
                ],
                [
                    'id' => 'housing_levy',
                    'label' => 'Housing levy (AHL)',
                    'formula' => sprintf(
                        'Employee: gross × %s%%. Employer: gross × %s%% (employer share is not deducted from net pay).',
                        round($hl['employee_rate'] * 100, 2),
                        round($hl['employer_rate'] * 100, 2),
                    ),
                    'rates' => [
                        'employee_rate_percent' => round($hl['employee_rate'] * 100, 2),
                        'employer_rate_percent' => round($hl['employer_rate'] * 100, 2),
                    ],
                ],
                [
                    'id' => 'paye',
                    'label' => 'PAYE',
                    'formula' => 'Taxable income = gross − NSSF − SHIF − housing levy (employee). Apply KRA monthly bands, then subtract personal relief and insurance relief.',
                    'rates' => [
                        'personal_relief_monthly' => $paye['personal_relief_monthly'],
                        'insurance_relief_cap_monthly' => $paye['insurance_relief_cap_monthly'],
                        'bands' => $bands,
                    ],
                ],
            ],
            'net_pay_formula' => 'Net pay = gross − (NSSF + SHIF + housing levy + PAYE + other deductions)',
            'other_deductions' => [
                'label' => 'Other deductions (loans, advances, custom)',
                'formula' => 'Fixed amounts and cash-advance repayments are deducted in full for the pay run (not reduced when basic pay is prorated for attendance). Percentage deductions use contract monthly gross (basic salary + monthly allowances), not prorated period gross.',
            ],
        ];
    }
}
