<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiFalseWriteSuccessGuard;
use PHPUnit\Framework\TestCase;

class AiFalseWriteSuccessGuardTest extends TestCase
{
    protected AiFalseWriteSuccessGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new AiFalseWriteSuccessGuard;
    }

    public function test_detects_false_lpo_created_claims(): void
    {
        $this->assertTrue($this->guard->looksLikeFalseWriteSuccess(
            "I've created LPO 4521 for Bidco with 3 lines."
        ));
        $this->assertTrue($this->guard->looksLikeFalseWriteSuccess(
            'The purchase order has been created successfully.'
        ));
        $this->assertTrue($this->guard->looksLikeFalseWriteSuccess(
            'I have saved the product and set the selling price.'
        ));
    }

    public function test_allows_non_success_replies(): void
    {
        $this->assertFalse($this->guard->looksLikeFalseWriteSuccess(
            'Share the supplier and line items, then reply **confirm** to create the LPO.'
        ));
        $this->assertFalse($this->guard->looksLikeFalseWriteSuccess(
            'Yesterday sales totaled KES 120,000.'
        ));
    }

    public function test_scrub_replaces_hallucinated_success_without_action_result(): void
    {
        $result = $this->guard->scrub([
            'reply' => "I've created the LPO for you.",
            'message' => "I've created the LPO for you.",
            'success' => true,
        ], ['type' => 'create_lpo']);

        $this->assertTrue($result['false_success_scrubbed'] ?? false);
        $this->assertStringContainsString('Nothing has been saved yet', (string) $result['reply']);
        $this->assertStringContainsString('confirm', (string) $result['reply']);
    }

    public function test_scrub_keeps_real_action_result(): void
    {
        $original = 'Purchase order (LPO) 99 created.';
        $result = $this->guard->scrub([
            'reply' => $original,
            'message' => $original,
            'action_result' => ['success' => true, 'message' => $original],
        ]);

        $this->assertSame($original, $result['reply']);
        $this->assertArrayNotHasKey('false_success_scrubbed', $result);
    }
}
