<?php

namespace App\Support;

/**
 * Normalize printed / spoken sales order and invoice references to sales.order_num.
 *
 * Shop invoices and receipts use S-prefix display (S1537 → 1537). Users often say
 * "invoice 1537" meaning the same order. AR invoices use AR-{order_num}.
 */
class SalesOrderReference
{
    /**
     * Extract integer order_num from S1537, #S001537, 1537, AR-1537, invoice 1537, etc.
     */
    public static function parseOrderNum(?string $raw): ?int
    {
        $term = trim((string) $raw);
        if ($term === '') {
            return null;
        }

        if (preg_match('/^#?S0*(\d+)$/i', $term, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        if (preg_match('/^AR[-_]?0*(\d+)(?:-S\d+)?$/i', $term, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        if (ctype_digit($term)) {
            $n = (int) $term;

            return $n > 0 ? $n : null;
        }

        if (preg_match('/\b(?:invoice|order|sale|receipt)\s*[#:]?\s*S?0*(\d+)\b/i', $term, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        if (preg_match('/\bAR[-_]?0*(\d+)\b/i', $term, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        if (preg_match('/\b#?S0*(\d+)\b/i', $term, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /** Display label matching the frontend formatOrderNumber (S + zero-pad 4). */
    public static function display(?int $orderNum): ?string
    {
        if ($orderNum === null || $orderNum <= 0) {
            return null;
        }

        return 'S'.str_pad((string) $orderNum, 4, '0', STR_PAD_LEFT);
    }
}
