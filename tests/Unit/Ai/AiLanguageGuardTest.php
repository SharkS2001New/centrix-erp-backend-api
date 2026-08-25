<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiLanguageGuard;
use PHPUnit\Framework\TestCase;

class AiLanguageGuardTest extends TestCase
{
    protected AiLanguageGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new AiLanguageGuard;
    }

    public function test_allows_english_erp_questions(): void
    {
        $this->assertTrue($this->guard->isEnglishQuery('What were yesterday\'s sales?'));
        $this->assertTrue($this->guard->isEnglishQuery('Where is GRN?'));
        $this->assertTrue($this->guard->isEnglishQuery('Show me unpaid debtors'));
    }

    public function test_declines_swahili_questions(): void
    {
        $this->assertFalse($this->guard->isEnglishQuery('nipe mauzo ya leo'));
        $this->assertFalse($this->guard->isEnglishQuery('deni ni ngapi kwa jumla?'));
        $this->assertFalse($this->guard->isEnglishQuery('Kwa nini mauzo yamepungua mwezi huu?'));
    }

    public function test_english_only_message_mentions_english(): void
    {
        $msg = $this->guard->englishOnlyMessage();
        $this->assertStringContainsString('English only', $msg);
    }
}
