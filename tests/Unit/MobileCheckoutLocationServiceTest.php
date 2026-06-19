<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\Sales\MobileCheckoutLocationService;
use Tests\TestCase;

class MobileCheckoutLocationServiceTest extends TestCase
{
    public function test_distance_within_radius_passes(): void
    {
        $service = new MobileCheckoutLocationService;
        $customer = new Customer([
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ]);

        $meta = $service->assertCheckoutLocation('mobile', [
            'mobile_enable_checkout_location_verification' => true,
            'mobile_allow_offline_orders' => false,
            'mobile_checkout_location_radius_metres' => 5,
        ], $customer, [
            'checkout_latitude' => -1.292100,
            'checkout_longitude' => 36.821910,
            'offline_order' => false,
        ]);

        $this->assertTrue($meta['verified']);
        $this->assertFalse($meta['offline_order']);
        $this->assertLessThanOrEqual(5, $meta['distance_metres']);
    }

    public function test_distance_outside_radius_fails(): void
    {
        $service = new MobileCheckoutLocationService;
        $customer = new Customer([
            'latitude' => -1.292100,
            'longitude' => 36.821900,
        ]);

        $this->expectExceptionMessage('Customer location must be within 5 metres radius.');

        $service->assertCheckoutLocation('mobile', [
            'mobile_enable_checkout_location_verification' => true,
            'mobile_allow_offline_orders' => true,
            'mobile_checkout_location_radius_metres' => 5,
        ], $customer, [
            'checkout_latitude' => -1.300000,
            'checkout_longitude' => 36.821900,
            'offline_order' => false,
        ]);
    }

    public function test_offline_order_allowed_when_setting_enabled(): void
    {
        $service = new MobileCheckoutLocationService;

        $meta = $service->assertCheckoutLocation('mobile', [
            'mobile_enable_checkout_location_verification' => true,
            'mobile_allow_offline_orders' => true,
            'mobile_checkout_location_radius_metres' => 5,
        ], null, [
            'offline_order' => true,
        ]);

        $this->assertTrue($meta['offline_order']);
        $this->assertFalse($meta['verified']);
    }

    public function test_location_check_skipped_when_setting_disabled(): void
    {
        $service = new MobileCheckoutLocationService;

        $meta = $service->assertCheckoutLocation('mobile', [
            'mobile_enable_checkout_location_verification' => false,
        ], null, []);

        $this->assertSame([], $meta);
    }
}
