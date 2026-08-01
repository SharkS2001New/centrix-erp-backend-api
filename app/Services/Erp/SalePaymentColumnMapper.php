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
}
