<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiCreateLpoParamMerger;
use App\Services\Ai\AiIntentResolver;
use Tests\TestCase;

/**
 * Pure extraction / intent tests — no ERP demo DB required.
 */
class AiCreateLpoSentenceExtractTest extends TestCase
{
    public function test_extracts_one_shot_sentence_lpo_details(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);

        $oneShot = $merger->extractFields(
            'Create an LPO for Bidco with Kamande x 10 due 30/09/2026'
        );
        $this->assertSame('Bidco', $oneShot['supplier'] ?? null);
        $this->assertSame('2026-09-30', $oneShot['due_date'] ?? null);
        $lines = $merger->parseLineList((string) ($oneShot['lines_raw'] ?? ''));
        $this->assertCount(1, $lines);
        $this->assertSame('Kamande', $lines[0]['product_name'] ?? null);
        $this->assertSame(10.0, $lines[0]['ordered_qty'] ?? null);
    }

    public function test_extracts_bags_of_and_multi_product_sentences(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);

        $bags = $merger->extractFields(
            'I need a purchase order from Kamande Supplies for 20 bags of sugar'
        );
        $this->assertSame('Kamande Supplies', $bags['supplier'] ?? null);
        $bagLines = $merger->parseLineList((string) ($bags['lines_raw'] ?? ''));
        $this->assertSame('sugar', mb_strtolower((string) ($bagLines[0]['product_name'] ?? '')));
        $this->assertSame(20.0, $bagLines[0]['ordered_qty'] ?? null);

        $multi = $merger->extractFields(
            'make lpo for Bidco Africa, 10 units of rice and sugar x 5'
        );
        $this->assertSame('Bidco Africa', $multi['supplier'] ?? null);
        $multiLines = $merger->parseLineList((string) ($multi['lines_raw'] ?? ''));
        $this->assertCount(2, $multiLines);
    }

    public function test_conversational_starters_infer_create_lpo(): void
    {
        $resolver = app(AiIntentResolver::class);

        foreach ([
            'I want an LPO',
            'help me with a purchase order',
            'LPO for Bidco',
            'Create LPO. Supplier is Bidco. Products: Sugar x 10, Cooking Oil x 5',
        ] as $message) {
            $action = $resolver->inferCreateAction($message, [], '/manager');
            $this->assertSame('create_lpo', $action['type'] ?? null, $message);
            $this->assertArrayNotHasKey('order_num', $action['params'] ?? []);
        }
    }

    public function test_voice_status_reply_prompts_missing_fields(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);

        $askSupplier = $merger->statusReply(['type' => 'create_lpo', 'params' => []], [], true);
        $this->assertStringContainsString('supplier', mb_strtolower($askSupplier));

        $askLines = $merger->statusReply([
            'type' => 'create_lpo',
            'params' => [
                'supplier_id' => 12,
                'supplier_name' => 'Bidco',
            ],
        ], [], true);
        $this->assertStringContainsString('product', mb_strtolower($askLines));

        $ready = $merger->statusReply([
            'type' => 'create_lpo',
            'params' => [
                'supplier_id' => 12,
                'supplier_name' => 'Bidco',
                'lines' => [
                    ['product_code' => 'KAM', 'product_name' => 'Kamande', 'ordered_qty' => 10],
                ],
            ],
        ], [], true);
        $this->assertStringContainsString('confirm', mb_strtolower($ready));
    }

    public function test_looks_like_field_follow_up_for_bare_replies(): void
    {
        $merger = app(AiCreateLpoParamMerger::class);
        $this->assertTrue($merger->looksLikeFieldFollowUp('Bidco'));
        $this->assertTrue($merger->looksLikeFieldFollowUp('Kamande x 10'));
        $this->assertTrue($merger->looksLikeFieldFollowUp('Supplier Bidco, Cooking Oil x 5'));
    }
}
