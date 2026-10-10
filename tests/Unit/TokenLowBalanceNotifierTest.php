<?php

namespace Tests\Unit;

use App\Services\Platform\TokenLowBalanceNotifier;
use PHPUnit\Framework\TestCase;

class TokenLowBalanceNotifierTest extends TestCase
{
    public function test_a_pack_of_500_warns_at_20_percent_and_10_percent(): void
    {
        $this->assertSame(0, TokenLowBalanceNotifier::levelFor(120, 500, 20, 10));
        $this->assertSame(1, TokenLowBalanceNotifier::levelFor(100, 500, 20, 10));
        $this->assertSame(1, TokenLowBalanceNotifier::levelFor(51, 500, 20, 10));
        $this->assertSame(2, TokenLowBalanceNotifier::levelFor(50, 500, 20, 10));
        $this->assertSame(2, TokenLowBalanceNotifier::levelFor(0, 500, 20, 10));
    }

    public function test_a_single_percent_is_one_warning(): void
    {
        $this->assertSame(0, TokenLowBalanceNotifier::levelFor(30, 100, 0, 0));
        $this->assertSame(1, TokenLowBalanceNotifier::levelFor(20, 100, 20, 0));
    }
}
