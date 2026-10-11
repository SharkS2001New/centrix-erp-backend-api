<?php

namespace Tests\Unit;

use App\Services\Platform\SelfServePlanComposer;
use Tests\TestCase;

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

        $this->assertSame('hospitality', $draft['industry']);
        $this->assertSame('yearly', $draft['interval']);
        $this->assertSame(15000.0, $draft['price']);
        $this->assertSame(['hotel_bar_pos'], $draft['workspace_keys']);
        $this->assertSame([], $draft['section_keys']);
        $this->assertCount(1, $draft['features']);
    }

    public function test_a_driving_school_plan_does_not_keep_a_shop_till(): void
    {
        $draft = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))->normalize([
            'name' => 'School year',
            'description' => 'Driving school lessons and fees',
            'industry' => 'driving',
            'interval' => 'yearly',
            'price' => 24000,
            'workspace_keys' => ['driving', 'pos', 'hotel_bar_pos'],
            'section_keys' => ['reports'],
        ]);

        $this->assertSame('driving', $draft['industry']);
        $this->assertSame(['driving'], $draft['workspace_keys']);
        $this->assertSame([], $draft['section_keys']);
    }

    public function test_a_short_description_is_enough_for_the_plan_prompt(): void
    {
        $prompt = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))->systemPrompt();

        $this->assertStringContainsString('A short line is enough', $prompt);
        $this->assertStringContainsString('pos (External POS)', $prompt);
        $this->assertStringContainsString('hotel_bar_pos (Hotel POS)', $prompt);
        $this->assertStringContainsString('sales_orders (Sales & orders)', $prompt);
        $this->assertStringContainsString('must not be mixed with retail', $prompt);
    }
}
