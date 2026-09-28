<?php

namespace Tests\Feature;

use App\Models\PlatformMarketingPortfolio;
use App\Models\User;
use App\Services\Platform\PlatformMarketingPortfolioService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformMarketingPortfolioTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::where('username', 'superadmin')->firstOrFail();
    }

    public function test_meta_lists_verticals_including_spa(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $ids = collect(
            $this->getJson('/api/v1/admin/platform-marketing-portfolios/meta')
                ->assertOk()
                ->json('verticals')
        )->pluck('id')->all();

        $this->assertContains('spa_salon', $ids);
        $this->assertContains('supermarket', $ids);
        $this->assertContains('hotel_bar', $ids);
    }

    public function test_can_create_update_share_and_download_pdf(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $create = $this->postJson('/api/v1/admin/platform-marketing-portfolios', [
            'prospect_name' => 'Amina Wanjiku',
            'business_name' => 'Amina Fresh Mart',
            'location' => 'Westlands, Nairobi',
            'vertical' => 'supermarket',
            'pain_points' => 'Multi-till reconciliation is slow; stockouts on milk.',
            'focus_modules' => ['pos', 'inventory', 'purchasing', 'reports'],
            'tone' => 'professional',
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertGreaterThan(0, $id);
        $this->assertSame('draft', $create->json('data.status'));
        $sections = $create->json('data.sections');
        $this->assertNotEmpty($sections);
        $this->assertNotEmpty($sections[0]['body'] ?? null);
        $this->assertContains('cover', collect($sections)->pluck('key')->all());

        $this->patchJson("/api/v1/admin/platform-marketing-portfolios/{$id}", [
            'title' => 'Centrix for Amina Fresh Mart',
            'status' => 'ready',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'Centrix for Amina Fresh Mart');

        $share = $this->postJson("/api/v1/admin/platform-marketing-portfolios/{$id}/share")
            ->assertOk();

        $token = $share->json('share_token');
        $this->assertNotEmpty($token);

        $this->getJson("/api/v1/marketing-portfolios/shared/{$token}")
            ->assertOk()
            ->assertJsonPath('data.business_name', 'Amina Fresh Mart')
            ->assertJsonPath('data.prospect_name', 'Amina Wanjiku')
            ->assertJsonMissingPath('data.prospect_email');

        $this->get("/api/v1/admin/platform-marketing-portfolios/{$id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertDatabaseHas('platform_marketing_portfolios', [
            'id' => $id,
            'created_by' => $this->superAdmin->id,
            'vertical' => 'supermarket',
            'status' => 'shared',
        ]);
    }

    public function test_non_admin_cannot_list_portfolios(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/admin/platform-marketing-portfolios')
            ->assertForbidden();
    }

    public function test_pdf_builds_from_sections(): void
    {
        $portfolio = PlatformMarketingPortfolio::query()->create([
            'title' => 'Centrix for Grace Wellness',
            'status' => 'ready',
            'industry' => 'hospitality',
            'vertical' => 'spa_salon',
            'prospect_name' => 'Grace',
            'business_name' => 'Grace Wellness',
            'pain_points' => 'Need retail POS and staff attendance.',
            'focus_modules' => ['pos', 'hr', 'reports'],
            'tone' => 'friendly',
            'sections' => [
                [
                    'key' => 'cover',
                    'title' => 'Cover',
                    'body' => 'Centrix for Grace Wellness',
                    'bullets' => ['Retail POS', 'Attendance'],
                    'image_key' => 'hero_spa',
                ],
            ],
            'created_by' => $this->superAdmin->id,
        ]);

        $pdf = app(PlatformMarketingPortfolioService::class)->buildPdf($portfolio);
        $this->assertNotEmpty($pdf['binary']);
        $this->assertStringEndsWith('.pdf', $pdf['filename']);
    }

    public function test_quick_generate_from_instruction_without_prospect_name(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $create = $this->postJson('/api/v1/admin/platform-marketing-portfolios', [
            'vertical' => 'supermarket',
            'instruction' => 'General Centrix portfolio for Nairobi supermarket SMEs — multi-till EOD and stock alerts.',
            'generate_now' => true,
            'tone' => 'professional',
        ])->assertCreated();

        $this->assertSame('Prospect', $create->json('data.prospect_name'));
        $this->assertSame('ready', $create->json('data.status'));
        $sections = $create->json('data.sections');
        $this->assertNotEmpty($sections);
        $this->assertStringContainsString(
            'multi-till',
            strtolower(implode(' ', array_column($sections, 'body'))),
        );
    }
}
