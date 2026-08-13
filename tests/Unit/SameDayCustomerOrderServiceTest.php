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

    public function test_fold_checkout_lines_by_sku_sums_matching_wholesale_rows(): void
    {
        $service = new SameDayCustomerOrderService();
        $lines = collect([
            (object) [
                'product_code' => 'SUGAR',
                'quantity' => 10,
                'uom' => 'Bag',
                'unit_price' => 200,
                'display_unit_price' => 10000,
                'discount_given' => 0,
                'product_vat' => 160,
                'amount' => 10000,
                'on_wholesale_retail' => 0,
            ],
            (object) [
                'product_code' => 'SUGAR',
                'quantity' => 5,
                'uom' => 'Bag',
                'unit_price' => 200,
                'display_unit_price' => 10000,
                'discount_given' => 50,
                'product_vat' => 80,
                'amount' => 5000,
                'on_wholesale_retail' => 0,
            ],
            (object) [
                'product_code' => 'SUGAR',
                'quantity' => 2,
                'uom' => 'kg',
                'unit_price' => 50,
                'display_unit_price' => 50,
                'discount_given' => 0,
                'product_vat' => 16,
                'amount' => 100,
                'on_wholesale_retail' => 1,
            ],
            (object) [
                'product_code' => 'RICE',
                'quantity' => 1,
                'uom' => 'Bag',
                'unit_price' => 300,
                'display_unit_price' => 300,
                'discount_given' => 0,
                'product_vat' => 0,
                'amount' => 300,
                'on_wholesale_retail' => 0,
            ],
        ]);

        $folded = $service->foldCheckoutLinesBySku($lines);

        $this->assertCount(3, $folded);
        $wholesaleSugar = $folded->first(
            fn ($line) => $line->product_code === 'SUGAR' && (int) $line->on_wholesale_retail === 0,
        );
        $this->assertNotNull($wholesaleSugar);
        $this->assertSame(15.0, (float) $wholesaleSugar->quantity);
        $this->assertSame(15000.0, (float) $wholesaleSugar->amount);
        $this->assertSame(50.0, (float) $wholesaleSugar->discount_given);
        $this->assertSame(240.0, (float) $wholesaleSugar->product_vat);

        $retailSugar = $folded->first(
            fn ($line) => $line->product_code === 'SUGAR' && (int) $line->on_wholesale_retail === 1,
        );
        $this->assertNotNull($retailSugar);
        $this->assertSame(2.0, (float) $retailSugar->quantity);

        $rice = $folded->first(fn ($line) => $line->product_code === 'RICE');
        $this->assertNotNull($rice);
        $this->assertSame(1.0, (float) $rice->quantity);
    }
}
