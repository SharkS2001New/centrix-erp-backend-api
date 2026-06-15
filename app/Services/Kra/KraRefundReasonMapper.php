<?php

namespace App\Services\Kra;

/**
 * Comstore / eTIMS refund reason codes (rfdRsnCd) for credit notes.
 *
 * @see Comstore API Documentation 2.1 — Table 6 Refund reason code
 */
class KraRefundReasonMapper
{
    /** @return array<string, string> */
    public static function codes(): array
    {
        return [
            '01' => 'Missing Quantity',
            '02' => 'Missing Data',
            '03' => 'Damaged / Wasted',
            '04' => 'Raw Material',
            '05' => 'Shortage',
            '06' => 'Refund',
        ];
    }

    public static function fromReturnReason(?string $reason): string
    {
        $text = strtolower(trim((string) $reason));

        return match (true) {
            str_contains($text, 'damaged') => '03',
            str_contains($text, 'defect') => '03',
            str_contains($text, 'wrong') => '02',
            str_contains($text, 'expired') => '05',
            str_contains($text, 'missing') => '01',
            default => '06',
        };
    }

    public static function label(?string $code): string
    {
        return self::codes()[$code ?? ''] ?? $code ?? 'Refund';
    }
}
