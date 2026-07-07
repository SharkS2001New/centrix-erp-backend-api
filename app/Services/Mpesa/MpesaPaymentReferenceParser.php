<?php

namespace App\Services\Mpesa;

class MpesaPaymentReferenceParser
{
    /**
     * @return array{order_num?: int, customer_num?: int}
     */
    public function parse(string $billRefNumber): array
    {
        $clean = strtoupper(trim($billRefNumber));
        if ($clean === '') {
            return [];
        }

        if (preg_match('/^S0*(\d+)$/i', $clean, $matches)) {
            return ['order_num' => (int) $matches[1]];
        }

        if (preg_match('/^C0*(\d+)$/i', $clean, $matches)) {
            return ['customer_num' => (int) $matches[1]];
        }

        if (preg_match('/^INV0*(\d+)$/i', $clean, $matches)) {
            return ['order_num' => (int) $matches[1]];
        }

        if (preg_match('/^\d+$/', $clean)) {
            return ['order_num' => (int) ltrim($clean, '0') ?: 0];
        }

        if (preg_match('/(?:^|[^0-9])(S0*(\d+))(?:[^0-9]|$)/i', $clean, $matches)) {
            return ['order_num' => (int) $matches[2]];
        }

        if (preg_match('/(?:^|[^0-9])(C0*(\d+))(?:[^0-9]|$)/i', $clean, $matches)) {
            return ['customer_num' => (int) $matches[2]];
        }

        return [];
    }
}
