<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiActionExecutor;
use Tests\TestCase;

class AiActionExecutorFormUiTest extends TestCase
{
    public function test_wants_form_ui_detects_explicit_form_requests(): void
    {
        $executor = app(AiActionExecutor::class);

        $this->assertTrue($executor->wantsFormUi('show form'));
        $this->assertTrue($executor->wantsFormUi('Please open the form'));
        $this->assertTrue($executor->wantsFormUi('I prefer the form instead'));
        $this->assertFalse($executor->wantsFormUi('yes'));
        $this->assertFalse($executor->wantsFormUi('Supplier is ABC Ltd, 10 bags of sugar'));
    }
}
