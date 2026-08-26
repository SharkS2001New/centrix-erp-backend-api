<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiIntentResolver;
use Tests\TestCase;

class AiLpoWorkflowIntentTest extends TestCase
{
    protected AiIntentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = app(AiIntentResolver::class);
    }

    public function test_submit_for_approval_inferred(): void
    {
        $action = $this->resolver->inferCreateAction('Submit LPO 42 for approval', [], null);
        $this->assertSame('submit_lpo_for_approval', $action['type'] ?? null);
        $this->assertSame(42, $action['params']['lpo_no'] ?? null);
    }

    public function test_approve_inferred(): void
    {
        $action = $this->resolver->inferCreateAction('Please approve purchase order 99', [], null);
        $this->assertSame('approve_lpo', $action['type'] ?? null);
        $this->assertSame(99, $action['params']['lpo_no'] ?? null);
    }

    public function test_mark_sent_inferred(): void
    {
        $action = $this->resolver->inferCreateAction('Mark LPO 7 as sent to supplier', [], null);
        $this->assertSame('mark_lpo_sent', $action['type'] ?? null);
        $this->assertSame(7, $action['params']['lpo_no'] ?? null);
    }

    public function test_receive_inferred(): void
    {
        $action = $this->resolver->inferCreateAction('Receive goods for LPO 15', [], null);
        $this->assertSame('receive_lpo_goods', $action['type'] ?? null);
        $this->assertSame(15, $action['params']['lpo_no'] ?? null);
        $this->assertTrue((bool) ($action['params']['receive_all'] ?? false));
    }

    public function test_create_still_preferred_over_workflow(): void
    {
        $action = $this->resolver->inferCreateAction('Create an LPO for supplier ABC', [], null);
        $this->assertSame('create_lpo', $action['type'] ?? null);
    }
}
