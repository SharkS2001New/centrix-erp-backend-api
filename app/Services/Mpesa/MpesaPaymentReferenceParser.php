<?php

namespace App\Services\Mpesa;

class MpesaPaymentReferenceParser
{
    /**
     * Parse a paybill / till BillRefNumber into an order or customer number.
     *
     * Supports fixed Safaricom account names (e.g. paybill 4036507, account "moon"):
     * customers often enter moonS12, moon S12, or moon12 — the account name is stripped
     * before matching the order reference.
     *
     * @return array{order_num?: int, customer_num?: int}
     */
    public function parse(string $billRefNumber, ?string $accountName = null): array
    {
        $clean = strtoupper(trim($billRefNumber));
        if ($clean === '') {
            return [];
        }

        $clean = $this->stripAccountNamePrefix($clean, $accountName);
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

    protected function stripAccountNamePrefix(string $cleanUpper, ?string $accountName): string
    {
        $prefix = strtoupper(trim((string) $accountName));
        if ($prefix === '' || strlen($prefix) < 2) {
            return $cleanUpper;
        }

        // Exact account name only (no order ref) → nothing to parse.
        if ($cleanUpper === $prefix) {
            return '';
        }

        if (! str_starts_with($cleanUpper, $prefix)) {
            return $cleanUpper;
        }

        $remainder = substr($cleanUpper, strlen($prefix));
        // moonS12, moon-S12, moon/S12, moon S12, moon12
        $remainder = ltrim($remainder, " \t-_/.");

        return $remainder;
    }
}
