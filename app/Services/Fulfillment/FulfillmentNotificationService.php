<?php

namespace App\Services\Fulfillment;

use App\Models\DispatchTrip;
use App\Models\Organization;
use App\Models\Sale;
use App\Services\Notifications\CustomerNotificationDispatcher;
use App\Services\Notifications\NotificationSettingsResolver;

class FulfillmentNotificationService
{
    public function __construct(protected CustomerNotificationDispatcher $dispatcher) {}

    public function notifyTripDispatch(DispatchTrip $trip, Organization $organization): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_dispatch'])) {
            return;
        }

        $trip->loadMissing(['sales', 'route']);
        $routeName = $trip->route?->route_name ?? 'your route';

        foreach ($trip->sales as $sale) {
            $this->dispatcher->notifySaleCustomer(
                $organization,
                $sale,
                $settings['dispatch_sms_template'],
                $settings['dispatch_email_template'],
                'Order {order_num} is on the way',
                [
                    'order_num' => $sale->order_num ?? $sale->id,
                    'route_name' => $routeName,
                    'trip_code' => $trip->trip_code,
                ],
            );
        }
    }

    public function notifyOrderDelivered(Sale $sale, Organization $organization): void
    {
        $settings = NotificationSettingsResolver::forOrganization($organization);
        if (empty($settings['notify_on_delivery'])) {
            return;
        }

        $this->dispatcher->notifySaleCustomer(
            $organization,
            $sale,
            $settings['delivery_sms_template'],
            $settings['delivery_email_template'],
            'Order {order_num} delivered',
            [
                'order_num' => $sale->order_num ?? $sale->id,
            ],
        );
    }
}
