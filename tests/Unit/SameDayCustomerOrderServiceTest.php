<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Erp\CapabilityGate;
use App\Services\Sales\SameDayCustomerOrderService;
use PHPUnit\Framework\TestCase;

class SameDayCustomerOrderServiceTest extends TestCase
{
    public function test_enabled_defaults_off(): void
    {
        $service = new SameDayCustomerOrderService();
        $this->assertFalse($service->enabled([]));
        $this->assertFalse($service->enabled(['append_same_day_customer_orders' => false]));
        $this->assertTrue($service->enabled(['append_same_day_customer_orders' => true]));
    }

    public function test_resolve_append_target_ignores_pos_and_backoffice(): void
    {
        $service = new SameDayCustomerOrderService();
        $user = new User();
        $user->organization_id = 1;
        $user->branch_id = 1;
        $gate = $this->createMock(CapabilityGate::class);
        $settings = ['append_same_day_customer_orders' => true];

        $this->assertNull($service->resolveAppendTarget(
            $gate,
            $user,
            $settings,
            42,
            1,
            'pos',
            null,
            null,
        ));
        $this->assertNull($service->resolveAppendTarget(
            $gate,
            $user,
            $settings,
            42,
            1,
            'backend',
            null,
            null,
        ));
    }

    public function test_find_open_order_today_rejects_non_mobile_channel(): void
    {
        $service = new SameDayCustomerOrderService();
        $user = new User();
        $user->organization_id = 1;
        $user->branch_id = 1;

        $this->assertNull($service->findOpenOrderToday($user, 42, 1, 'pos'));
        $this->assertNull($service->findOpenOrderToday($user, 42, 1, 'backend'));
    }
}
