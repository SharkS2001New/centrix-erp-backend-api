<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\Operations\CartOperationsController;
use App\Models\StockReservation;
use App\Models\User;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class StockReservationCartLineIdTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_reserve_stock_ignores_stale_cart_line_id(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $controller = app(CartOperationsController::class);
        $method = new \ReflectionMethod($controller, 'reserveStock');
        $method->setAccessible(true);

        $reservation = $method->invoke(
            $controller,
            (int) $admin->branch_id,
            '6161100100015',
            1.0,
            'shop',
            (int) $admin->id,
            null,
            true,
            999_999_999,
        );

        $this->assertInstanceOf(StockReservation::class, $reservation);
        $this->assertNull($reservation->cart_line_id);
    }
}
