<?php

namespace App\Services\Erp;

use App\Models\Sale;

class SalePaymentColumnMapper
{
    /** @return array<string, float> column => increment amount */
    public static function incrementsForMethod(string $methodCode, float $amount): array
    {
        if ($amount <= 0) {
            return [];
        }

        $code = strtoupper(trim($methodCode));

        return match ($code) {
            'CASH' => ['cash' => $amount],
            'MPESA', 'AIRTEL' => ['mpesa_amount' => $amount],
            'EQUITY' => ['equity_amount' => $amount],
            'KCB' => ['kcb_amount' => $amount],
            // Admin / platform banks (COOP, ABSA, …) live on sale_payments only —
            // do not dump them into cash (that would break alone/mixed till maths).
            default => [],
        };
    }

    public static function applyToSale(Sale $sale, string $methodCode, float $amount): void
    {
        $increments = self::incrementsForMethod($methodCode, $amount);
        if ($increments === []) {
            return;
        }

        $updates = [];
        foreach ($increments as $column => $delta) {
            $updates[$column] = (float) ($sale->{$column} ?? 0) + $delta;
        }

        $sale->update($updates);
    }

    /** Zero tender columns so a rebuild can set absolute method amounts. */
    public static function resetTenderColumns(Sale $sale): void
    {
        $sale->update([
            'cash' => 0,
            'mpesa_amount' => 0,
            'equity_amount' => 0,
            'kcb_amount' => 0,
        ]);
    }

    /**
     * Replace tender columns from a method_code => amount map (absolute values).
     *
     * @param  array<string, float>  $methodAmounts
     */
    public static function replaceFromMethodMap(Sale $sale, array $methodAmounts): void
    {
        self::resetTenderColumns($sale);
        $sale->refresh();
        foreach ($methodAmounts as $methodCode => $amount) {
            if ((float) $amount > 0) {
                self::applyToSale($sale->fresh(), (string) $methodCode, (float) $amount);
            }
        }
    }
}
