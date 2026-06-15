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
            default => ['cash' => $amount],
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
