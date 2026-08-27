<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiAssistantHelpGuide;
use Tests\TestCase;

class AiAssistantHelpGuideTest extends TestCase
{
    public function test_detects_help_requests(): void
    {
        $guide = new AiAssistantHelpGuide;

        $this->assertTrue($guide->isHelpRequest('Help'));
        $this->assertTrue($guide->isHelpRequest('help?'));
        $this->assertTrue($guide->isHelpRequest('What can you do'));
        $this->assertTrue($guide->isHelpRequest('what can i ask'));
        $this->assertFalse($guide->isHelpRequest('Help me create a product'));
        $this->assertFalse($guide->isHelpRequest('Yesterday sales'));
    }

    public function test_reply_covers_main_topics(): void
    {
        $guide = new AiAssistantHelpGuide;
        $reply = $guide->reply('Backoffice');

        $this->assertStringContainsString('Backoffice', $reply);
        $this->assertStringContainsString('Sales', $reply);
        $this->assertStringContainsString('LPO', $reply);
        $this->assertStringContainsString('Help', $reply);
        $this->assertStringContainsString('@', $reply);
    }
}
