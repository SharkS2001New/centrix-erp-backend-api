<?php

namespace Tests\Feature;

use App\Models\PlatformMarketingPortfolio;
use App\Models\User;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Platform\PlatformMarketingPortfolioService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        $response = $this->getJson('/api/v1/admin/platform-marketing-portfolios/meta')->assertOk();
        $ids = collect($response->json('verticals'))->pluck('id')->all();

        $this->assertContains('spa_salon', $ids);
        $this->assertContains('driving_school', $ids);
        $this->assertContains('supermarket', $ids);
        $this->assertContains('hotel_bar', $ids);
        $this->assertContains('modern', collect($response->json('templates'))->pluck('id')->all());
        $this->assertNotEmpty($response->json('section_suggestions.driving_school.fit'));
    }

    public function test_template_is_persisted_for_driving_school_portfolios(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $create = $this->postJson('/api/v1/admin/platform-marketing-portfolios', [
            'prospect_name' => 'Nairobi Driving Academy',
            'vertical' => 'driving_school',
            'template_key' => 'editorial',
            'focus_modules' => ['driving', 'accounting', 'reports'],
        ])->assertCreated();

        $id = (int) $create->json('data.id');
        $this->assertSame('driving', $create->json('data.industry'));
        $this->assertSame('editorial', $create->json('data.template_key'));
        $this->assertStringContainsString(
            'student enrolment',
            strtolower(implode(' ', array_column($create->json('data.sections'), 'body'))),
        );

        $this->patchJson("/api/v1/admin/platform-marketing-portfolios/{$id}", [
            'template_key' => 'bold',
        ])->assertOk()->assertJsonPath('data.template_key', 'bold');
    }

    public function test_template_fallback_generation_preserves_uploaded_section_photos(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $platformOrganization = AiSettingsResolver::platformOrganization(refresh: true);
        $this->assertNotNull($platformOrganization);
        $originalSettings = $platformOrganization->module_settings;
        $originalGeminiKey = config('ai.gemini.api_key');
        $originalOpenAiKey = config('ai.platform_training.api_key');
        $platformOrganization->forceFill(['module_settings' => []])->save();
        Config::set('ai.gemini.api_key', '');
        Config::set('ai.platform_training.api_key', '');
        AiSettingsResolver::platformOrganization(refresh: true);

        try {
            $portfolio = app(PlatformMarketingPortfolioService::class)->create($this->superAdmin, [
                'vertical' => 'small_shop',
                'prospect_name' => 'Photo Shop',
            ]);
            $sections = $portfolio->sections;
            $sections[0]['image_path'] = "marketing-portfolios/{$portfolio->id}/cover.jpg";
            $sections[0]['image_url'] = '/storage/'.$sections[0]['image_path'];
            $portfolio->forceFill(['sections' => $sections])->save();

            $generated = app(PlatformMarketingPortfolioService::class)->generate($portfolio->fresh());

            $this->assertSame(
                $sections[0]['image_path'],
                collect($generated->sections)->firstWhere('key', 'cover')['image_path'],
            );
            $this->assertSame(
                $sections[0]['image_url'],
                collect($generated->sections)->firstWhere('key', 'cover')['image_url'],
            );
        } finally {
            $platformOrganization->forceFill(['module_settings' => $originalSettings])->save();
            Config::set('ai.gemini.api_key', $originalGeminiKey);
            Config::set('ai.platform_training.api_key', $originalOpenAiKey);
            AiSettingsResolver::platformOrganization(refresh: true);
        }
    }

    public function test_ai_can_generate_section_images_from_portfolio_copy_without_replacing_uploaded_photos(): void
    {
        Sanctum::actingAs($this->superAdmin);

        $platformOrganization = AiSettingsResolver::platformOrganization(refresh: true);
        $this->assertNotNull($platformOrganization);
        $originalSettings = $platformOrganization->module_settings;
        $originalApiKey = config('ai.platform_training.api_key');
        $originalBaseUrl = config('ai.platform_training.base_url');
        $platformOrganization->forceFill(['module_settings' => []])->save();
        Config::set('ai.platform_training.api_key', 'sk-test-image-generation');
        Config::set('ai.platform_training.base_url', 'https://api.openai.com/v1');
        AiSettingsResolver::platformOrganization(refresh: true);
        $testStorageRoot = sys_get_temp_dir().'/centrix-marketing-portfolio-'.bin2hex(random_bytes(8));
        Storage::set('public', Storage::build($testStorageRoot));

        $imageBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/r0kAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($imageBytes);
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [['b64_json' => base64_encode($imageBytes)]],
            ]),
        ]);

        try {
            $portfolio = app(PlatformMarketingPortfolioService::class)->create($this->superAdmin, [
                'vertical' => 'small_shop',
                'business_name' => 'Amina Fresh Mart',
                'pain_points' => 'Multi-till reconciliation is slow.',
            ]);
            $sections = $portfolio->sections;
            $uploadedPath = "marketing-portfolios/{$portfolio->id}/uploaded/cover.jpg";
            Storage::disk('public')->put($uploadedPath, 'uploaded-photo');
            $understandingIndex = collect($sections)->search(fn (array $section) => $section['key'] === 'understanding');
            $sections[$understandingIndex]['image_path'] = $uploadedPath;
            $sections[$understandingIndex]['image_url'] = Storage::disk('public')->url($uploadedPath);
            $portfolio->forceFill(['sections' => $sections])->save();

            $response = $this->postJson(
                "/api/v1/admin/platform-marketing-portfolios/{$portfolio->id}/generate-images",
            )->assertOk();

            $generatedKeys = $response->json('generated_sections');
            $this->assertCount(count($sections) - 1, $generatedKeys);
            $this->assertNotContains('understanding', $generatedKeys);
            $this->assertSame(
                $uploadedPath,
                collect($response->json('data.sections'))->firstWhere('key', 'understanding')['image_path'],
            );
            $this->assertNotEmpty(
                collect($response->json('data.sections'))->firstWhere('key', 'cover')['image_path'],
            );
            foreach ($response->json('data.sections') as $section) {
                if (in_array($section['key'], $generatedKeys, true)) {
                    Storage::disk('public')->assertExists($section['image_path']);
                }
            }
            Http::assertSent(fn ($request) => str_contains($request->url(), '/images/generations')
                && str_contains($request->data()['prompt'], 'Section details:')
                && str_contains($request->data()['prompt'], 'Amina Fresh Mart'));
            Http::assertSentCount(count($sections) - 1);
        } finally {
            Storage::disk('public')->deleteDirectory('marketing-portfolios');
            rmdir($testStorageRoot);
            $platformOrganization->forceFill(['module_settings' => $originalSettings])->save();
            Config::set('ai.platform_training.api_key', $originalApiKey);
            Config::set('ai.platform_training.base_url', $originalBaseUrl);
            AiSettingsResolver::platformOrganization(refresh: true);
        }
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
