<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiNearMissHelper;
use Tests\TestCase;

class AiNearMissHelperTest extends TestCase
{
    public function test_format_no_exact_with_closest_match(): void
    {
        $text = AiNearMissHelper::formatNoExact(
            'duka moja',
            ['label' => 'DUKA MOJA ACHIEVERS ACADEMY', 'reason' => 'name contains "duka"'],
            [['label' => 'DUKA YAKO MUSLIM RD', 'reason' => 'name contains "duka"']],
        );

        $this->assertStringContainsString("couldn't find an exact match", $text);
        $this->assertStringContainsString('DUKA MOJA ACHIEVERS ACADEMY', $text);
        $this->assertStringContainsString('DUKA YAKO MUSLIM RD', $text);
    }

    public function test_ambiguous_payload_lists_candidates(): void
    {
        $payload = AiNearMissHelper::ambiguous('john', [
            ['label' => 'John Kamau', 'username' => 'jkamau'],
            ['label' => 'John Doe', 'username' => 'jdoe'],
        ], 'employee');

        $this->assertTrue($payload['near_miss']);
        $this->assertStringContainsString('John Kamau', $payload['message']);
        $this->assertStringContainsString('Which one did you mean', $payload['message']);
    }

    public function test_score_name_match_prefers_contains(): void
    {
        $exact = AiNearMissHelper::scoreNameMatch('duka', 'DUKA MOJA ACHIEVERS');
        $weak = AiNearMissHelper::scoreNameMatch('duka', 'SUPA SHOP KAYOLE');

        $this->assertGreaterThan($weak, $exact);
    }
}
