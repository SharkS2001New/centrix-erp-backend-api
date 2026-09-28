<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use Tests\TestCase;

class MobileCartReservationTtlTest extends TestCase
{
    public function test_mobile_ttl_defaults_to_thirty_minutes_and_pos_stays_capped(): void
    {
        $subject = new class
        {
            use HandlesInventory;

            public function ttl(array $settings, ?string $channel): int
            {
                return $this->cartReservationTtlMinutes($settings, $channel);
            }
        };

        $this->assertSame(15, $subject->ttl(['cart_reservation_ttl_minutes' => 15], 'pos'));
        $this->assertSame(15, $subject->ttl(['cart_reservation_ttl_minutes' => 60], 'pos'));
        $this->assertSame(30, $subject->ttl([], 'mobile'));
        $this->assertSame(180, $subject->ttl(['mobile_cart_reservation_ttl_minutes' => 180], 'mobile'));
        $this->assertSame(480, $subject->ttl(['mobile_cart_reservation_ttl_minutes' => 999], 'mobile'));
    }
}
