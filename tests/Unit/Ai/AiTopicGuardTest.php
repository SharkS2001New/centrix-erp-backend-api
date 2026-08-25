<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiTopicGuard;
use PHPUnit\Framework\TestCase;

class AiTopicGuardTest extends TestCase
{
    public function test_unpaid_customer_name_queries_are_erp_related(): void
    {
        $guard = new AiTopicGuard;

        $this->assertTrue($guard->isErpRelated('Check any other vivian who is unpaid'));
        $this->assertTrue($guard->isErpRelated('Who is unpaid among my customers?'));
        $this->assertTrue($guard->isErpRelated('Show overdue debtors named Vivian'));
        $this->assertTrue($guard->isErpRelated('Customer statement for @Vivian for August'));
    }

    public function test_true_trivia_stays_off_topic(): void
    {
        $guard = new AiTopicGuard;

        $this->assertFalse($guard->isErpRelated('Who is the president of Kenya?'));
        $this->assertFalse($guard->isErpRelated('What is the weather forecast for Nairobi today?'));
        $this->assertFalse($guard->isErpRelated('Who won the world cup?'));
    }

    public function test_common_erp_intents_stay_in_scope(): void
    {
        $guard = new AiTopicGuard;

        $this->assertTrue($guard->isErpRelated('What is low stock right now?'));
        $this->assertTrue($guard->isErpRelated('Create a new product HALISI-20L'));
        $this->assertTrue($guard->isErpRelated('Supplier statement for August'));
        $this->assertTrue($guard->isErpRelated('How do I run payroll?'));
    }
}
