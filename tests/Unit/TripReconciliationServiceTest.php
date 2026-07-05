<?php

namespace Tests\Unit;

use App\Services\Fulfillment\TripReconciliationService;
use ReflectionMethod;
use Tests\TestCase;

class TripReconciliationServiceTest extends TestCase
{
    public function test_unpaid_resolved_order_count_ignores_credit_and_failed_stops(): void
    {
        $service = app(TripReconciliationService::class);
        $method = new ReflectionMethod(TripReconciliationService::class, 'unpaidResolvedOrderCount');
        $method->setAccessible(true);

        $count = $method->invoke($service, [
            [
                'is_resolved' => true,
                'is_failed_delivery' => false,
                'is_credit_sale' => false,
                'balance_due' => 0,
                'return_amount' => 0,
            ],
            [
                'is_resolved' => true,
                'is_failed_delivery' => false,
                'is_credit_sale' => true,
                'balance_due' => 500,
                'return_amount' => 0,
            ],
            [
                'is_resolved' => true,
                'is_failed_delivery' => true,
                'is_credit_sale' => false,
                'balance_due' => 200,
                'return_amount' => 0,
            ],
            [
                'is_resolved' => true,
                'is_failed_delivery' => false,
                'is_credit_sale' => false,
                'balance_due' => 150,
                'return_amount' => 0,
            ],
        ]);

        $this->assertSame(1, $count);
    }

    public function test_unpaid_resolved_order_count_subtracts_returns(): void
    {
        $service = app(TripReconciliationService::class);
        $method = new ReflectionMethod(TripReconciliationService::class, 'unpaidResolvedOrderCount');
        $method->setAccessible(true);

        $count = $method->invoke($service, [
            [
                'is_resolved' => true,
                'is_failed_delivery' => false,
                'is_credit_sale' => false,
                'balance_due' => 100,
                'return_amount' => 100,
            ],
        ]);

        $this->assertSame(0, $count);
    }
}
