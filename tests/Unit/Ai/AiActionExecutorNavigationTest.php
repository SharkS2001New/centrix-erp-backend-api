<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiActionExecutor;
use Tests\TestCase;

class AiActionExecutorNavigationTest extends TestCase
{
    public function test_navigation_actions_are_not_write_actions(): void
    {
        $executor = app(AiActionExecutor::class);

        $this->assertTrue($executor->isNavigationAction('navigate_orders'));
        $this->assertTrue($executor->isNavigationAction('open_lpo'));
        $this->assertFalse($executor->isNavigationAction('create_product'));
        $this->assertFalse($executor->isWriteAction('navigate_orders'));
        $this->assertTrue($executor->isWriteAction('create_supplier'));
        $this->assertTrue($executor->isWriteAction('record_customer_payment'));
    }

    public function test_navigate_orders_returns_deep_link_without_mutation(): void
    {
        $outcome = app(AiActionExecutor::class)->execute(
            new \App\Models\User,
            [
                'type' => 'navigate_orders',
                'params' => [
                    'href' => '/sales/orders?q=mainge',
                    'q' => 'mainge',
                ],
            ],
        );

        $this->assertTrue($outcome['success']);
        $this->assertTrue($outcome['result']['navigate'] ?? false);
        $this->assertSame('/sales/orders?q=mainge', $outcome['result']['path'] ?? null);
    }
}
