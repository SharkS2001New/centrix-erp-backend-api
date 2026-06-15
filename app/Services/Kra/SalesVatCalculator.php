<?php

namespace App\Services\Kra;

/**
 * VAT helpers aligned with Kenya fiscal device conventions (LightStoresApp).
 * Line amounts are treated as VAT-inclusive when a rate applies.
 */
class SalesVatCalculator
{
    public static function vatRateFromProduct(?object $product): float
    {
        if (! $product) {
            return 0.0;
        }

        $vat = $product->vat ?? null;
        if ($vat && isset($vat->vat_percentage)) {
            return max(0, (float) $vat->vat_percentage);
        }

        return 0.0;
    }

    /** Extract VAT portion from a VAT-inclusive gross line amount. */
    public static function vatFromInclusiveGross(float $gross, float $vatRate): float
    {
        if ($gross <= 0 || $vatRate <= 0) {
            return 0.0;
        }

        $net = $gross / (1 + ($vatRate / 100));

        return round(max(0, $gross - $net), 2);
    }

    public static function netFromInclusiveGross(float $gross, float $vatRate): float
    {
        if ($gross <= 0) {
            return 0.0;
        }

        if ($vatRate <= 0) {
            return round($gross, 2);
        }

        return round($gross / (1 + ($vatRate / 100)), 2);
    }

    /**
     * Split line totals into KRA VAT buckets (16% vs exempt).
     *
     * @param  iterable<int, array{amount: float, product_vat?: float, quantity?: float, product_name?: string}>  $items
     * @return array{vat16_net: float, vat16_value: float, exempt_net: float}
     */
    public static function summarizeForKra(iterable $items): array
    {
        $vat16Net = 0.0;
        $vat16Value = 0.0;
        $vatExemptNet = 0.0;

        foreach ($items as $item) {
            $amount = (float) ($item['amount'] ?? 0);
            $quantity = (float) ($item['quantity'] ?? 1);
            if ($amount <= 0) {
                continue;
            }

            $vatAmount = (float) ($item['product_vat'] ?? 0);

            if ($vatAmount > 0) {
                $netAmount = $amount - $vatAmount;
                if ($netAmount > 0) {
                    $vatRate = ($vatAmount / $netAmount) * 100;
                    if (abs($vatRate - 16) < 1) {
                        $netAmount = $amount / 1.16;
                        $vatAmount = $amount - $netAmount;
                    }
                } else {
                    $netAmount = $amount / 1.16;
                    $vatAmount = $amount - $netAmount;
                }

                $vat16Net += $netAmount;
                $vat16Value += $vatAmount;
            } else {
                $vatExemptNet += $amount;
            }

            unset($quantity);
        }

        return [
            'vat16_net' => round($vat16Net, 2),
            'vat16_value' => round($vat16Value, 2),
            'exempt_net' => round($vatExemptNet, 2),
        ];
    }
}
