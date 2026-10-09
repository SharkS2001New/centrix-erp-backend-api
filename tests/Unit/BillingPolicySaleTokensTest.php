<?php

namespace Tests\Unit;

use App\Services\Platform\BillingPolicy;
use PHPUnit\Framework\TestCase;

class BillingPolicySaleTokensTest extends TestCase
{
    public function test_sale_cost_falls_back_to_the_old_create_price(): void
    {
        $policy = (new BillingPolicy)->normalize([
            'write_costs' => ['create' => 2, 'update' => 1, 'delete' => 1],
        ]);

        $this->assertSame(2, $policy['sale_tokens']);
    }

    public function test_published_sale_tokens_win(): void
    {
        $policy = (new BillingPolicy)->normalize([
            'sale_tokens' => 3,
            'write_costs' => ['create' => 2],
        ]);

        $this->assertSame(3, $policy['sale_tokens']);
    }
}
