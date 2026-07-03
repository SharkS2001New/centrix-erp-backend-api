<?php

namespace App\Services\Notifications;

class NotificationActionUrlBuilder
{
    public static function for(string $type, int $referenceId, ?array $payload = null): string
    {
        return match ($type) {
            'leave_request' => '/hr/leave?leave_day_id='.$referenceId,
            'customer_return' => '/sales/returns?return_id='.$referenceId,
            'supplier_return' => '/suppliers/returns?return_id='.$referenceId,
            'journal_entry' => '/accounting/journal-entries/'.$referenceId,
            'order_cancel' => '/sales/orders/'.$referenceId,
            'discount' => '/pos',
            'stock_adjustment' => '/inventory/adjustments',
            'stock_transfer' => '/inventory/transfers',
            default => '/notifications',
        };
    }

    public static function absolute(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $base = rtrim((string) config('erp.frontend_url', ''), '/');
        if ($base === '') {
            return $path;
        }

        return $base.(str_starts_with($path, '/') ? $path : '/'.$path);
    }
}
