<?php

namespace Tests\Unit;

use App\Services\Notifications\InAppNotificationEvents;
use App\Services\Notifications\NotificationSettingsResolver;
use Tests\TestCase;

class InAppNotificationSettingsTest extends TestCase
{
    public function test_in_app_event_defaults_can_be_disabled(): void
    {
        $settings = NotificationSettingsResolver::normalize([
            'in_app_notify_on_trip_activity' => false,
            'in_app_notify_on_whatsapp_handoff' => true,
        ]);

        $this->assertFalse(
            NotificationSettingsResolver::inAppEventEnabled($settings, InAppNotificationEvents::TRIP_ACTIVITY),
        );
        $this->assertTrue(
            NotificationSettingsResolver::inAppEventEnabled($settings, InAppNotificationEvents::WHATSAPP_HANDOFF),
        );
    }

    public function test_approval_request_defaults_to_enabled(): void
    {
        $settings = NotificationSettingsResolver::normalize([]);

        $this->assertTrue(
            NotificationSettingsResolver::inAppEventEnabled($settings, InAppNotificationEvents::APPROVAL_REQUEST),
        );
        $this->assertTrue(
            NotificationSettingsResolver::inAppEventEnabled($settings, InAppNotificationEvents::APPROVAL_OUTCOME),
        );
    }
}
