<?php

namespace Tests\Unit\Sales;

use App\Services\Sales\ReceiptPaymentDetailsResolver;
use Tests\TestCase;

class ReceiptPaymentDetailsResolverTest extends TestCase
{
    public function test_normalizes_multiple_blocks_and_drops_blank_lines(): void
    {
        $normalized = ReceiptPaymentDetailsResolver::normalize([
            'title' => 'Payment details',
            'blocks' => [
                [
                    'title' => 'Equity Bank',
                    'lines' => [
                        ['label' => 'Account no.', 'value' => '111'],
                        ['label' => '', 'value' => ''],
                        ['label' => 'Swift code', 'value' => 'EQBLKENA'],
                    ],
                ],
                [
                    'title' => 'KCB',
                    'lines' => [
                        ['label' => 'Account no.', 'value' => '222'],
                    ],
                ],
            ],
            'note' => '',
        ]);

        $this->assertNotNull($normalized);
        $this->assertCount(2, $normalized['blocks']);
        $this->assertSame('Equity Bank', $normalized['blocks'][0]['title']);
        $this->assertCount(2, $normalized['blocks'][0]['lines']);
        $this->assertSame('EQBLKENA', $normalized['blocks'][0]['lines'][1]['value']);
        $this->assertCount(3, $normalized['lines']);
    }

    public function test_migrates_legacy_flat_lines_into_one_block(): void
    {
        $normalized = ReceiptPaymentDetailsResolver::normalize([
            'title' => 'Payment details',
            'lines' => [
                ['label' => 'Till', 'value' => '55'],
            ],
            'note' => '',
        ]);

        $this->assertNotNull($normalized);
        $this->assertCount(1, $normalized['blocks']);
        $this->assertSame('55', $normalized['blocks'][0]['lines'][0]['value']);
    }

    public function test_empty_details_normalize_to_null(): void
    {
        $this->assertNull(ReceiptPaymentDetailsResolver::normalize([
            'title' => 'Payment details',
            'blocks' => [
                ['title' => '', 'lines' => [['label' => '', 'value' => '']]],
            ],
            'note' => '',
        ]));
    }
}
