<?php

namespace App\Services\Driving;

/**
 * Money and timetable rules that do not need the database.
 * Collected cash is never treated as profit.
 */
class DrivingScheduleRules
{
    public static function overlaps(string $startA, string $endA, string $startB, string $endB): bool
    {
        return $startA < $endB && $endA > $startB;
    }

    public static function invoiceNet(float $amount, float $discount): float
    {
        return round($amount - $discount, 2);
    }

    /** Outstanding fees = invoiced net − payments kept + refunds given back. */
    public static function outstanding(float $invoicedNet, float $paid, float $refunded): float
    {
        return round($invoicedNet - $paid + $refunded, 2);
    }
}
