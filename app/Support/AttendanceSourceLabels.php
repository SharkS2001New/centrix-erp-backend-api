<?php

namespace App\Support;

class AttendanceSourceLabels
{
    public static function label(?string $source): string
    {
        return match ($source) {
            'field_rep' => 'Mobile sales app',
            'company_mobile' => 'Premises (company phone)',
            'clock_device' => 'Premises (clock)',
            'manual' => 'Manual entry',
            default => 'Manual entry',
        };
    }

    /** High-level login channel for unified attendance views and payroll reporting. */
    public static function channel(?string $source): string
    {
        return match ($source) {
            'field_rep' => 'mobile_sales',
            'clock_device', 'company_mobile' => 'premises',
            default => 'manual',
        };
    }

    public static function channelLabel(?string $source): string
    {
        return match (self::channel($source)) {
            'mobile_sales' => 'Mobile sales app',
            'premises' => 'Premises',
            default => 'Manual entry',
        };
    }
}
