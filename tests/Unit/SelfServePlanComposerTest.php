<?php

namespace Tests\Unit;

use App\Services\Platform\SelfServePlanComposer;
use PHPUnit\Framework\TestCase;

class SelfServePlanComposerTest extends TestCase
{
    public function test_it_keeps_only_known_modules_and_a_yearly_price(): void
    {
        $draft = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))->normalize([
            'name' => 'Hotel year',
            'description' => 'Rooms and the bar',
            'interval' => 'yearly',
            'price' => 15000,
            'features' => [
                ['label' => 'Hotel POS', 'detail' => 'Bar and room charge'],
                ['label' => ''],
            ],
            'workspace_keys' => ['hotel_bar_pos', 'not-a-module'],
            'section_keys' => ['reports', 'made-up'],
        ]);

        $this->assertSame('yearly', $draft['interval']);
        $this->assertSame(15000.0, $draft['price']);
        $this->assertSame(['hotel_bar_pos'], $draft['workspace_keys']);
        $this->assertSame(['reports'], $draft['section_keys']);
        $this->assertCount(1, $draft['features']);
    }
}
