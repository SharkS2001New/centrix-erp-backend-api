<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\V1\MobileOrdersQuickActionsController;
use App\Models\CustomerReturn;
use ReflectionMethod;
use Tests\TestCase;

class MobileOrdersReturnsQueryTest extends TestCase
{
    public function test_constrain_to_mobile_sale_uses_exists_not_join(): void
    {
        $controller = app(MobileOrdersQuickActionsController::class);
        $query = CustomerReturn::query()
            ->where('customer_returns.organization_id', 3)
            ->where('customer_returns.status', 'approved');

        $method = new ReflectionMethod($controller, 'constrainToMobileSale');
        $method->invoke($controller, $query, [
            'cashier_id' => null,
            'route_id' => 120,
        ]);

        $sql = strtolower($query->toSql());

        $this->assertStringContainsString('exists', $sql);
        $this->assertStringContainsString('mobile_sale', $sql);
        $this->assertStringNotContainsString('inner join `sales` as `mobile_sale`', $sql);
        $this->assertStringContainsString('`customer_returns`.`organization_id`', $sql);
        $this->assertStringContainsString('`customer_returns`.`status`', $sql);
        // Bare organization_id / status after a sales join is what broke production.
        $this->assertDoesNotMatchRegularExpression(
            '/join\s+`?sales`?\s+as\s+`?mobile_sale`?.*where\s+`organization_id`\s*=/s',
            $sql,
        );
    }
}
