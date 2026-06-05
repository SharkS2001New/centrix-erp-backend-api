<?php

namespace App\Support;

final class LpoStatus
{
    public const AWAITING_CHECK = 0;

    public const AWAITING_APPROVAL = 1;

    public const AWAITING_SEND = 2;

    public const AWAITING_RECEIVE = 3;

    public const AWAITING_LAST_RECEIVE = 4;

    public const FULLY_RECEIVED = 5;

    public const CLEARED = 6;

    public const CANCELLED_RETURNED = 7;

    /** Sent to supplier — no edit/delete after this stage. */
    public static function sentThreshold(): int
    {
        return self::AWAITING_RECEIVE;
    }
}
