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
            'discount' => self::discountActionUrl($referenceId, $payload),
            'stock_adjustment' => '/inventory/adjustments',
            'stock_transfer' => '/inventory/transfers',
            'lpo' => '/lpo/'.$referenceId,
            'payroll_run' => '/hr/payroll/runs/'.$referenceId,
            'cash_advance' => '/hr/cash-advances?advance_id='.$referenceId,
            'expense' => $referenceId > 0 ? '/accounting/expenses?expense_id='.$referenceId : '/accounting/expenses',
            'stock_take' => '/inventory/stock-take/'.$referenceId,
            'damage' => $referenceId > 0 ? '/inventory/damages?damage_id='.$referenceId : '/inventory/damages',
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

    /** @param  array<string, mixed>|null  $payload */
    public static function discountActionUrl(int $referenceId, ?array $payload = null): string
    {
        if ($referenceId > 0 && (($payload['sale_id'] ?? null) || ($payload['order_num'] ?? null))) {
            $channel = strtolower((string) ($payload['channel'] ?? ''));

            return $channel === 'mobile'
                ? '/sales/orders/queues/mobile'
                : '/sales/orders/queues/pending-approval';
        }

        return self::discountCartUrl($payload);
    }

    public static function discountEditableActionUrl(?array $payload = null): string
    {
        $channel = strtolower((string) ($payload['channel'] ?? ''));

        return $channel === 'mobile'
            ? '/mobile/orders?status=editable'
            : '/sales/orders/queues/editable';
    }

    /** @param  array<string, mixed>|null  $payload */
    public static function discountCartUrl(?array $payload = null): string
    {
        $channel = strtolower((string) ($payload['channel'] ?? ''));
        $source = strtolower((string) ($payload['order_source'] ?? ''));

        if (in_array($channel, ['backend', 'backoffice'], true) || $source === 'backoffice') {
            return '/sales/pos';
        }

        return '/pos';
    }
}
