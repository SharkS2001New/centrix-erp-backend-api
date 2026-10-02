<?php

namespace App\Services\Payroll;

/**
 * When cash-advance repayment exceeds what net pay can absorb after statutory
 * + non-advance deductions, take only what is available and leave the remainder
 * open for the next cycle.
 */
class PayrollCashAdvanceNetGuard
{
    /**
     * @param  array<string, mixed>  $calc
     * @param  array<string, mixed>  $lineInput
     * @return array{calc: array<string, mixed>, line: array<string, mixed>}
     */
    public function apply(array $calc, array $lineInput, float $periodGross): array
    {
        $meta = is_array($lineInput['payroll_meta'] ?? null) ? $lineInput['payroll_meta'] : [];
        $detail = is_array($meta['deductions_detail'] ?? null) ? $meta['deductions_detail'] : [];
        if ($detail === []) {
            return ['calc' => $calc, 'line' => $lineInput];
        }

        $hasAdvance = false;
        foreach ($detail as $item) {
            if (($item['type'] ?? '') === 'cash_advance') {
                $hasAdvance = true;
                break;
            }
        }
        if (! $hasAdvance) {
            return ['calc' => $calc, 'line' => $lineInput];
        }

        $periodGross = round(max(0, $periodGross), 2);
        $statutory = round(
            (float) ($calc['nssf'] ?? 0)
            + (float) ($calc['shif'] ?? 0)
            + (float) ($calc['housing_levy'] ?? 0)
            + (float) ($calc['paye'] ?? 0),
            2,
        );

        $nonAdvance = 0.0;
        foreach ($detail as $item) {
            if (($item['type'] ?? '') === 'cash_advance') {
                continue;
            }
            $nonAdvance += round((float) ($item['amount'] ?? 0), 2);
        }
        $nonAdvance = round($nonAdvance, 2);

        $availableForAdvances = round(max(0, $periodGross - $statutory - $nonAdvance), 2);
        $remaining = $availableForAdvances;
        $changed = false;

        foreach ($detail as $index => $item) {
            if (($item['type'] ?? '') !== 'cash_advance') {
                continue;
            }
            $scheduled = round((float) ($item['amount'] ?? 0), 2);
            if ($scheduled <= 0) {
                continue;
            }
            $take = round(min($scheduled, $remaining), 2);
            if ($take + 0.0001 < $scheduled) {
                $detail[$index]['amount_scheduled'] = $scheduled;
                $detail[$index]['amount'] = $take;
                $detail[$index]['capped_to_net'] = true;
                $changed = true;
            }
            $remaining = round(max(0, $remaining - $take), 2);
        }

        if (! $changed) {
            return ['calc' => $calc, 'line' => $lineInput];
        }

        $other = 0.0;
        foreach ($detail as $item) {
            $other += round((float) ($item['amount'] ?? 0), 2);
        }
        $other = round($other, 2);

        $meta['deductions_detail'] = $detail;
        $meta['cash_advances_capped_to_net'] = true;
        $lineInput['payroll_meta'] = $meta;
        $lineInput['other_deductions'] = $other;

        $calc['other_deductions'] = $other;
        $calc['deductions'] = round($statutory + $other, 2);
        $calc['net_pay'] = round(max(0, $periodGross - $calc['deductions']), 2);
        if (isset($calc['payroll']) && is_array($calc['payroll'])) {
            $calc['payroll'] = array_merge($calc['payroll'], $meta);
        }

        return ['calc' => $calc, 'line' => $lineInput];
    }
}
