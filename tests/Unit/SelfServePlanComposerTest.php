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

    public function test_a_chosen_industry_overrides_words_that_sound_like_another_trade(): void
    {
        $draft = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))->normalize([
            'name' => 'Front desk',
            'description' => 'Driving school lessons and fees',
            'industry' => 'driving',
            'price' => 3000,
            'workspace_keys' => ['driving', 'pos', 'spa'],
            'section_keys' => ['reports'],
        ], 'spa');

        $this->assertSame('spa', $draft['industry']);
        $this->assertSame(['spa'], $draft['workspace_keys']);
        $this->assertSame([], $draft['section_keys']);
    }

    public function test_a_written_shop_brief_becomes_a_retail_plan_at_2000(): void
    {
        $words = <<<'TXT'
Retail & Distribution
Shop

Till and products — Sell at the counter, with prices, tax, and a receipt.
Stock on hand — See what is left and receive goods from suppliers.
Daily sales — Customers, today’s takings, and a simple sales report.

at 2000 Ksh
TXT;
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $this->assertNull($composer->draftFromBrief($words, 'commerce'));

        $brief = $composer->readBrief($words, 'commerce');
        $this->assertSame('Shop', $brief['name']);
        $this->assertSame('commerce', $brief['industry']);
        $this->assertSame('monthly', $brief['interval']);
        $this->assertSame(2000.0, $brief['price']);
        $this->assertSame([
            ['label' => 'Till and products', 'detail' => 'Sell at the counter, with prices, tax, and a receipt.'],
            ['label' => 'Stock on hand', 'detail' => 'See what is left and receive goods from suppliers.'],
            ['label' => 'Daily sales', 'detail' => 'Customers, today’s takings, and a simple sales report.'],
        ], $brief['features']);
        $workspaces = $composer->workspacesFor('commerce', $words);
        $sections = $composer->sectionsFor('commerce', $words);
        $this->assertContains('pos', $workspaces);
        $this->assertContains('backoffice', $workspaces);
        $this->assertNotContains('hotel_bar_pos', $workspaces);
        $this->assertContains('products', $sections);
        $this->assertContains('inventory', $sections);
        $this->assertContains('suppliers', $sections);
        $this->assertContains('sales_orders', $sections);
        $this->assertContains('reports', $sections);
    }

    public function test_a_price_line_can_be_yearly(): void
    {
        $brief = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))
            ->readBrief("Hotel year\nRooms — Bar and room charge.\nat 15000 Ksh a year", 'hospitality');

        $draft = [
            'name' => $brief['name'],
            'interval' => $brief['interval'],
            'price' => $brief['price'],
            'workspace_keys' => (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))
                ->workspacesFor('hospitality', 'Hotel year'),
            'section_keys' => [],
        ];

        $this->assertNotNull($draft);
        $this->assertSame('Hotel year', $draft['name']);
        $this->assertSame('yearly', $draft['interval']);
        $this->assertSame(15000.0, $draft['price']);
        $this->assertSame(['hotel_bar_pos', 'hospitality_backoffice', 'admin'], $draft['workspace_keys']);
        $this->assertSame([], $draft['section_keys']);
    }

    public function test_a_short_line_without_card_lines_is_left_for_the_model(): void
    {
        $draft = (new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class)))
            ->draftFromBrief('Small shop, 2000 a month', 'commerce');

        $this->assertNull($draft);
    }

    public function test_fewer_than_eight_written_points_are_left_for_the_model(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $words = "Shop plan for a counter, stock, and daily sales.\n";
        $words .= implode("\n", array_map(
            fn (int $n) => "Point {$n} — Detail for card point {$n}.",
            range(1, 7),
        ));
        $words .= "\nat 2000 Ksh";

        $this->assertGreaterThanOrEqual(SelfServePlanComposer::MIN_BRIEF_CHARS, mb_strlen($words));
        $this->assertNull($composer->draftFromBrief($words, 'commerce', 8));
    }

    public function test_eight_written_points_are_kept_and_extras_are_dropped(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $words = "Shop\nA counter plan for a small retail shop that sells goods and tracks stock.\n";
        $words .= implode("\n", array_map(
            fn (int $n) => "Point {$n} — Detail for card point {$n}.",
            range(1, 10),
        ));
        $words .= "\nat 2000 Ksh";

        $draft = $composer->draftFromBrief($words, 'commerce', 8);

        $this->assertNotNull($draft);
        $this->assertCount(8, $draft['features']);
        $this->assertSame('Point 1', $draft['features'][0]['label']);
        $this->assertSame('Point 8', $draft['features'][7]['label']);
    }

    public function test_a_short_line_and_a_low_point_count_are_rejected(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));

        try {
            $composer->guardBriefLength('Small shop, 2000 a month');
            $this->fail('A short line should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
        }

        try {
            $composer->normalizePointCount(7);
            $this->fail('Fewer than 8 points should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('points', $e->errors());
        }

        $this->assertSame(8, $composer->normalizePointCount(8));
        $this->assertSame(12, $composer->normalizePointCount(12));
    }

    public function test_a_plan_is_written_back_as_editable_text(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $plan = new \App\Models\PlatformPlan([
            'name' => 'Shop',
            'industry' => 'commerce',
            'description' => 'Counter sales for a small shop.',
            'interval' => 'monthly',
            'monthly_price' => 2000,
            'feature_details' => [
                ['label' => 'Till and products', 'detail' => 'Sell at the counter.'],
            ],
        ]);

        $brief = $composer->briefFromPlan($plan);

        $this->assertStringContainsString('Retail & Distribution', $brief);
        $this->assertStringContainsString('Shop', $brief);
        $this->assertStringContainsString('Till and products — Sell at the counter.', $brief);
        $this->assertStringContainsString('at 2000 Ksh', $brief);
    }

    public function test_an_edit_needs_a_change_note(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $plan = new \App\Models\PlatformPlan([
            'name' => 'Shop',
            'industry' => 'commerce',
            'interval' => 'monthly',
            'monthly_price' => 2000,
        ]);

        try {
            $composer->revisePlan($plan, 'price', 'commerce', 8);
            $this->fail('A tiny edit note should be rejected.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertArrayHasKey('description', $e->errors());
        }
    }

    public function test_the_prompt_asks_for_the_chosen_number_of_points(): void
    {
        $composer = new SelfServePlanComposer($this->createStub(\App\Services\Ai\AiProviderFactory::class));
        $prompt = $composer->systemPrompt(12);

        $this->assertStringContainsString('A short line is not enough', $prompt);
        $this->assertStringContainsString('exactly 12 feature points', $prompt);
        $this->assertStringNotContainsString('A short line is enough', $prompt);
        $this->assertStringContainsString('pos (External POS)', $prompt);
        $this->assertStringContainsString('hotel_bar_pos (Hotel POS)', $prompt);
        $this->assertStringContainsString('sales_orders (Sales & orders)', $prompt);
        $this->assertStringContainsString('Create exactly 10 feature points.', $composer->userPrompt('A full shop description.', 'commerce', 10));
    }
}
