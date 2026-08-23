<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiIntentResolver;
use Tests\TestCase;

class AiIntentResolverTest extends TestCase
{
    protected AiIntentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new AiIntentResolver;
    }

    public function test_sales_question_does_not_infer_create_product_from_history(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'Create product Mumias Sugar 2KG subcategory SUGAR'],
        ];

        $this->assertNull(
            $this->resolver->inferCreateAction('Whats my daily sales for yesterday', $history, '/sales'),
        );
    }

    public function test_create_product_still_inferred_from_current_message(): void
    {
        $this->assertSame(
            'create_product',
            $this->resolver->inferCreateAction('Create product called Widget', [], '/dashboard')['type'] ?? null,
        );
    }

    public function test_cancel_intent_is_detected(): void
    {
        $this->assertTrue($this->resolver->isCancelIntent('cancel'));
        $this->assertTrue($this->resolver->isCancelIntent('never mind'));
    }

    public function test_data_question_is_detected(): void
    {
        $this->assertTrue($this->resolver->isDataQuestion('Whats my daily sales for yesterday'));
        $this->assertFalse($this->resolver->isDataQuestion('Create product Widget'));
    }
}
