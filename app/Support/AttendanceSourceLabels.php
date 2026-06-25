<?php

namespace App\Support;

class AttendanceSourceLabels
{
    public static function label(?string $source): string
    {
        return match ($source) {
            'field_rep' => 'Field rep',
            'company_mobile' => 'Company phone',
            'clock_device' => 'Clock',
            'manual' => 'Manual',
            default => 'Manual',
        };
    }
}
