<?php

namespace App\Support;

class SalesChannelLabels
{
    /** Normalize channel codes for dashboard/report grouping. */
    public static function metricKey(?string $channel): string
    {
        return match (strtolower(trim((string) $channel))) {
            'backoffice', 'backend' => 'erp',
            'mobile' => 'mobile',
            'pos' => 'pos',
            'whatsapp' => 'whatsapp',
            default => strtolower(trim((string) $channel)) ?: 'other',
        };
    }

    public static function label(?string $channelOrKey): string
    {
        $key = self::metricKey($channelOrKey);

        return match ($key) {
            'erp' => 'ERP',
            'mobile' => 'Mobile',
            'pos' => 'POS',
            'whatsapp' => 'WhatsApp',
            'other' => 'Other',
            default => ucfirst($key),
        };
    }
}
