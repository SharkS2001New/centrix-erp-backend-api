<?php

namespace App\Services\Notifications;

class InAppNotificationEvents
{
    public const TRIP_ACTIVITY = 'trip_activity';

    public const WHATSAPP_HANDOFF = 'whatsapp_handoff';

    public const ORDER_STATUS_CHANGE = 'order_status_change';

    public const FISCAL_PERIOD_CHANGE = 'fiscal_period_change';

    public const STOCK_RECEIPT = 'stock_receipt';

    public const STOCK_TRANSFER = 'stock_transfer';

    public const BANK_RECONCILIATION = 'bank_reconciliation';

    public const YEAR_END_CLOSE = 'year_end_close';

    public const APPROVAL_REQUEST = 'approval_request';

    public const APPROVAL_OUTCOME = 'approval_outcome';

    public const SYSTEM_ISSUE = 'system_issue';

    /** @return list<string> */
    public static function organizationEvents(): array
    {
        return [
            self::TRIP_ACTIVITY,
            self::WHATSAPP_HANDOFF,
            self::ORDER_STATUS_CHANGE,
            self::FISCAL_PERIOD_CHANGE,
            self::STOCK_RECEIPT,
            self::STOCK_TRANSFER,
            self::BANK_RECONCILIATION,
            self::YEAR_END_CLOSE,
            self::APPROVAL_REQUEST,
            self::APPROVAL_OUTCOME,
        ];
    }

    public static function settingKey(string $event): string
    {
        return 'in_app_notify_on_'.$event;
    }
}
