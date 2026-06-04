<?php

namespace App\Support;

class AttendanceHours
{
    /** @return float|null Hours between HH:MM or HH:MM:SS times; supports overnight out < in */
    public static function fromTimeStrings(?string $checkIn, ?string $checkOut): ?float
    {
        if ($checkIn === null || $checkIn === '' || $checkOut === null || $checkOut === '') {
            return null;
        }

        $in = strtotime('1970-01-01 '.$checkIn);
        $out = strtotime('1970-01-01 '.$checkOut);
        if ($in === false || $out === false) {
            return null;
        }

        if ($out <= $in) {
            $out += 86400;
        }

        return round(($out - $in) / 3600, 2);
    }
}
