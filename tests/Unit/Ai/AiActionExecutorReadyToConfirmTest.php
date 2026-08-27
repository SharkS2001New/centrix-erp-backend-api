<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiActionExecutor;
use Tests\TestCase;

class AiActionExecutorReadyToConfirmTest extends TestCase
{
    public function test_create_lpo_is_not_ready_without_supplier_and_lines(): void
    {
        $executor = app(AiActionExecutor::class);

        $this->assertFalse($executor->isReadyToConfirm([
            'type' => 'create_lpo',
            'params' => [],
        ]));

        $this->assertFalse($executor->isReadyToConfirm([
            'type' => 'create_lpo',
            'params' => ['supplier_id' => 12],
        ]));

        $this->assertTrue($executor->isReadyToConfirm([
            'type' => 'create_lpo',
            'params' => [
                'supplier_id' => 12,
                'lines' => [
                    ['product_code' => 'SUGAR', 'ordered_qty' => 10],
                ],
            ],
        ]));

        $this->assertTrue($executor->isReadyToConfirm([
            'type' => 'create_lpo',
            'params' => [
                'supplier_id' => 12,
                'order_num' => 'SO-100',
            ],
        ]));
    }

    public function test_create_product_ready_when_name_present(): void
    {
        $executor = app(AiActionExecutor::class);

        $this->assertFalse($executor->isReadyToConfirm([
            'type' => 'create_product',
            'params' => [],
        ]));

        $this->assertTrue($executor->isReadyToConfirm([
            'type' => 'create_product',
            'params' => ['product_name' => 'Widget'],
        ]));
    }

    public function test_not_ready_message_mentions_missing_lpo_details(): void
    {
        $executor = app(AiActionExecutor::class);
        $message = $executor->notReadyToConfirmMessage(['type' => 'create_lpo', 'params' => []]);

        $this->assertStringContainsString('supplier', strtolower($message));
        $this->assertStringContainsString('confirm', strtolower($message));
    }

    public function test_confirmation_matches_markdown_confirm(): void
    {
        $executor = app(AiActionExecutor::class);

        $this->assertTrue($executor->isConfirmation('confirm'));
        $this->assertTrue($executor->isConfirmation('**confirm**'));
        $this->assertTrue($executor->isConfirmation('Confirm.'));
        $this->assertTrue($executor->isConfirmation('create it'));
        $this->assertTrue($executor->isConfirmation('save'));
        $this->assertTrue($executor->isConfirmation('save the product'));
        $this->assertTrue($executor->isConfirmation('save this LPO'));
        $this->assertFalse($executor->isConfirmation('please confirm tomorrow'));
        $this->assertFalse($executor->isConfirmation('save a copy for later review'));
    }
}
