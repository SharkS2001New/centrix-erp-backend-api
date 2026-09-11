<?php

namespace Tests\Unit;

use App\Services\Kra\KraDeviceErrorTranslator;
use App\Services\Sales\CreditNoteService;
use Tests\TestCase;

class KraCreditNoteSoftFailMessagingTest extends TestCase
{
    public function test_credit_note_timeout_message_does_not_say_sale_was_saved(): void
    {
        $message = KraDeviceErrorTranslator::userMessageForDocument(
            'CentrixKraAgent did not respond in time for /api/complete-workflow',
            'credit_note',
        );

        $this->assertStringNotContainsString('sale was saved', strtolower($message));
        $this->assertStringContainsString('return was approved', strtolower($message));
    }

    public function test_timeout_is_treated_as_transient_for_queue(): void
    {
        $this->assertTrue(KraDeviceErrorTranslator::isTransientConnectivityFailure(
            'Centrix KRA Agent / Comstore did not respond in time.',
        ));
        $this->assertFalse(KraDeviceErrorTranslator::isTransientConnectivityFailure(
            'This invoice has already been fully credited on the KRA device.',
        ));
    }

    public function test_pending_retry_message_mentions_hourly_queue(): void
    {
        $message = CreditNoteService::pendingRetryMessage('Agent offline');
        $this->assertStringContainsString('queued', strtolower($message));
        $this->assertStringContainsString('automatically', strtolower($message));
        $this->assertStringContainsString('Agent offline', $message);
    }
}
