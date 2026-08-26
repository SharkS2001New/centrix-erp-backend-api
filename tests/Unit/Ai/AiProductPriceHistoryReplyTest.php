<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiToolResultReplyBuilder;
use Tests\TestCase;

class AiProductPriceHistoryReplyTest extends TestCase
{
    public function test_fallback_formats_price_history_table(): void
    {
        $builder = new AiToolResultReplyBuilder;
        $reply = $builder->build([
            [
                'name' => 'get_product_price_history',
                'result' => [
                    'source' => 'price_history',
                    'product' => [
                        'product_name' => 'SUGAR 50 KG',
                        'current_unit_price' => 6200,
                        'current_last_cost_price' => 6000,
                    ],
                    'history' => [
                        [
                            'changed_at_label' => '25 Aug 2026 10:00',
                            'unit_price' => 6200,
                            'cost_price' => 6000,
                            'discount_pct' => 0,
                            'changed_by_name' => 'Admin',
                        ],
                    ],
                    'screens' => [['label' => 'Price history', 'path' => '/price-history']],
                    'tip' => 'Answer from this formal Centrix price_history ledger.',
                ],
            ],
        ]);

        $this->assertStringContainsString('SUGAR 50 KG', $reply);
        $this->assertStringContainsString('6,200.00', $reply);
        $this->assertStringContainsString('/price-history', $reply);
        $this->assertStringNotContainsString('doesn\'t keep a formal price-change log', $reply);
        $this->assertStringNotContainsString('Answer from this formal', $reply);
    }
}
