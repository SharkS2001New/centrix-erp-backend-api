<?php

namespace App\Services\Payroll;

class KenyaStatutoryCalculator
{
    /**
     * Calculate monthly Kenya statutory deductions from gross pay.
     *
     * @return array{
     *   gross_pay: float,
     *   nssf: float,
     *   nssf_tier1: float,
     *   nssf_tier2: float,
     *   shif: float,
     *   housing_levy: float,
     *   taxable_income: float,
     *   paye_before_relief: float,
     *   personal_relief: float,
     *   insurance_relief: float,
     *   paye: float,
     *   other_deductions: float,
     *   deductions: float,
     *   net_pay: float,
     *   employer_nssf: float,
     *   employer_housing: float,
     *   employer_total: float,
     *   effective_label: string
     * }
     */
    public function calculateMonthly(
        float $grossPay,
        float $otherDeductions = 0,
        float $privateInsurancePremiums = 0,
    ): array {
        $gross = round(max(0, $grossPay), 2);
        $other = round(max(0, $otherDeductions), 2);
        $cfg = KenyaPayrollSettingsResolver::resolve();

        $nssfParts = $this->nssf($gross, $cfg['nssf']);
        $nssf = $nssfParts['total'];
        $shif = $this->shif($gross, $cfg['shif']);
        $housing = round($gross * (float) $cfg['housing_levy']['employee_rate'], 2);

        // SHIF is an allowable deduction from taxable income (Tax Laws Amendment Act 2024).
        // It does NOT also qualify for insurance relief.
        $taxable = round(max(0, $gross - $nssf - $shif - $housing), 2);
        $payeBeforeRelief = $this->progressiveTax($taxable, $cfg['paye']['bands']);
        $personalRelief = (float) $cfg['paye']['personal_relief_monthly'];
        $insuranceRelief = $this->insuranceRelief($privateInsurancePremiums, $cfg['paye']);
        $paye = round(max(0, $payeBeforeRelief - $personalRelief - $insuranceRelief), 2);

        $statutory = round($nssf + $shif + $housing + $paye, 2);
        $deductions = round($statutory + $other, 2);
        $net = round(max(0, $gross - $deductions), 2);

        $employerNssf = $nssf;
        $employerHousing = round($gross * (float) $cfg['housing_levy']['employer_rate'], 2);

        return [
            'gross_pay' => $gross,
            'nssf' => $nssf,
            'nssf_tier1' => $nssfParts['tier1'],
            'nssf_tier2' => $nssfParts['tier2'],
            'shif' => $shif,
            'housing_levy' => $housing,
            'taxable_income' => $taxable,
            'paye_before_relief' => round($payeBeforeRelief, 2),
            'personal_relief' => $personalRelief,
            'insurance_relief' => round($insuranceRelief, 2),
            'paye' => $paye,
            'other_deductions' => $other,
            'deductions' => $deductions,
            'net_pay' => $net,
            'employer_nssf' => $employerNssf,
            'employer_housing' => $employerHousing,
            'employer_total' => round($employerNssf + $employerHousing, 2),
            'effective_label' => $cfg['effective_label'] ?? '2026',
        ];
    }

    /** @param array<string, mixed> $nssfCfg */
    protected function nssf(float $gross, array $nssfCfg): array
    {
        $rate = (float) $nssfCfg['rate'];
        $tier1Upper = (float) $nssfCfg['tier1_upper'];
        $tier2Upper = (float) $nssfCfg['tier2_upper'];

        $tier1 = round(min($gross, $tier1Upper) * $rate, 2);
        $tier2Base = max(0, min($gross, $tier2Upper) - $tier1Upper);
        $tier2 = round($tier2Base * $rate, 2);

        return [
            'tier1' => $tier1,
            'tier2' => $tier2,
            'total' => round($tier1 + $tier2, 2),
        ];
    }

    /** @param array<string, mixed> $shifCfg */
    protected function shif(float $gross, array $shifCfg): float
    {
        $amount = round($gross * (float) $shifCfg['rate'], 2);

        return max($amount, (float) $shifCfg['minimum_monthly']);
    }

    /**
     * Private insurance premiums only (life/health/education). SHIF is excluded.
     *
     * @param  array<string, mixed>  $payeCfg
     */
    protected function insuranceRelief(float $privatePremiums, array $payeCfg): float
    {
        $premiums = max(0, $privatePremiums);
        if ($premiums <= 0) {
            return 0.0;
        }

        $rate = (float) ($payeCfg['insurance_relief_rate'] ?? 0.15);
        $cap = (float) ($payeCfg['insurance_relief_cap_monthly'] ?? 5000);

        return min(round($premiums * $rate, 2), $cap);
    }

    /** @param list<array{up_to: int|null, rate: float}> $bands */
    protected function progressiveTax(float $taxable, array $bands): float
    {
        $remaining = $taxable;
        $previousCap = 0;
        $tax = 0.0;

        foreach ($bands as $band) {
            $cap = $band['up_to'] === null ? PHP_FLOAT_MAX : (float) $band['up_to'];
            $width = max(0, min($remaining, $cap - $previousCap));
            if ($width <= 0) {
                $previousCap = $cap;
                continue;
            }
            $tax += $width * (float) $band['rate'];
            $remaining -= $width;
            $previousCap = $cap;
            if ($remaining <= 0) {
                break;
            }
        }

        return round($tax, 2);
    }
}
