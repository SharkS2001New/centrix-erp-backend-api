<?php

namespace Tests\Feature;

use App\Models\PlatformMarketingBlog;
use App\Models\User;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Platform\MarketingBlogHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformMarketingBlogTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_html_sanitizer_strips_scripts_and_keeps_images(): void
    {
        $html = '<p>Hello</p><script>alert(1)</script><img src="https://example.com/a.jpg" alt="x" onerror="alert(1)"><a href="javascript:alert(1)">bad</a>';
        $clean = MarketingBlogHtml::sanitize($html);
        $this->assertStringContainsString('<p>Hello</p>', $clean);
        $this->assertStringContainsString('<img', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
    }

    public function test_html_sanitizer_preserves_word_tables_and_safe_formatting(): void
    {
        $clean = MarketingBlogHtml::sanitize(
            '<h2 style="font-size:20pt;color:#185fa5">Prices</h2>'
            .'<table><thead><tr><th style="background-color:#f2f2f2">Package</th><th>Price</th></tr></thead>'
            .'<tbody><tr><td>Starter</td><td>KES 1,500</td></tr></tbody></table>',
        );

        $this->assertStringContainsString('<h2 style="font-size: 20pt; color: #185fa5">Prices</h2>', $clean);
        $this->assertStringContainsString('<table>', $clean);
        $this->assertStringContainsString('<thead>', $clean);
        $this->assertStringContainsString('<th style="background-color: #f2f2f2">Package</th>', $clean);
        $this->assertStringContainsString('KES 1,500', $clean);
    }

    public function test_super_admin_can_publish_html_blog_with_image_upload(): void
    {
        Storage::fake('public');
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $upload = $this->post('/api/v1/admin/marketing-blogs/upload-image', [
            'image' => UploadedFile::fake()->image('cover.jpg', 1600, 900),
        ]);
        $upload->assertCreated();
        $url = $upload->json('url');
        $this->assertNotEmpty($url);

        $create = $this->postJson('/api/v1/admin/marketing-blogs', [
            'title' => 'Debtors guide',
            'excerpt' => 'How debtors work',
            'body' => '<p>Track balances.</p><img src="'.$url.'" alt="cover">',
            'cover_image_url' => $url,
            'category' => 'guides',
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        $this->postJson("/api/v1/admin/marketing-blogs/{$id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $slug = $create->json('data.slug');
        $public = $this->getJson('/api/v1/public/marketing-blogs/'.$slug);
        $public->assertOk()
            ->assertJsonPath('data.title', 'Debtors guide')
            ->assertJsonPath('data.body_is_html', true);

        $this->assertTrue(
            PlatformMarketingBlog::query()->where('slug', $slug)->firstOrFail()->isPublished()
        );
    }

    public function test_super_admin_can_generate_blog_cover_image_from_article_content(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $platformOrganization = AiSettingsResolver::platformOrganization(refresh: true);
        $this->assertNotNull($platformOrganization);
        $originalSettings = $platformOrganization->module_settings;
        $originalApiKey = config('ai.platform_training.api_key');
        $originalBaseUrl = config('ai.platform_training.base_url');
        $platformOrganization->forceFill(['module_settings' => []])->save();
        Config::set('ai.platform_training.api_key', 'sk-test-blog-cover');
        Config::set('ai.platform_training.base_url', 'https://api.openai.com/v1');
        AiSettingsResolver::platformOrganization(refresh: true);
        $storageRoot = sys_get_temp_dir().'/centrix-blog-cover-'.bin2hex(random_bytes(8));
        Storage::fake('public', $storageRoot);
        Http::fake([
            'api.openai.com/v1/images/generations' => Http::response([
                'data' => [[
                    'b64_json' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/r0kAAAAASUVORK5CYII=',
                ]],
            ]),
        ]);

        try {
            $blog = $this->postJson('/api/v1/admin/marketing-blogs', [
                'title' => 'Smarter stock control for retail teams',
                'excerpt' => 'How connected inventory helps reduce stockouts.',
                'body' => '<p>Retail teams can use real-time stock levels and low-stock alerts to make better purchasing decisions.</p>',
                'category' => 'guides',
            ])->assertCreated();

            $id = $blog->json('data.id');
            $response = $this->postJson("/api/v1/admin/marketing-blogs/{$id}/generate-cover-image", [
                'instruction' => 'Use a warm, contemporary retail setting.',
            ])->assertOk()
                ->assertJsonPath('message', 'Cover image generated from the blog content.');

            $coverUrl = $response->json('data.cover_image_url');
            $this->assertNotEmpty($coverUrl);
            $storedPath = parse_url($coverUrl, PHP_URL_PATH);
            $this->assertIsString($storedPath);
            $this->assertStringContainsString('marketing-blogs/', $storedPath);
            Storage::disk('public')->assertExists(substr($storedPath, strlen('/storage/')));
            Http::assertSent(fn ($request) => str_contains($request->url(), '/images/generations')
                && str_contains($request->data()['prompt'], 'Smarter stock control for retail teams')
                && str_contains($request->data()['prompt'], 'real-time stock levels')
                && str_contains($request->data()['prompt'], 'warm, contemporary retail setting'));
        } finally {
            Storage::disk('public')->deleteDirectory('marketing-blogs');
            rmdir($storageRoot);
            $platformOrganization->forceFill(['module_settings' => $originalSettings])->save();
            Config::set('ai.platform_training.api_key', $originalApiKey);
            Config::set('ai.platform_training.base_url', $originalBaseUrl);
            AiSettingsResolver::platformOrganization(refresh: true);
        }
    }

    public function test_draft_title_change_updates_slug(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $create = $this->postJson('/api/v1/admin/marketing-blogs', [
            'title' => 'First title',
            'body' => 'Plain body text',
        ])->assertCreated();

        $id = $create->json('data.id');
        $this->patchJson("/api/v1/admin/marketing-blogs/{$id}", [
            'title' => 'Second title',
        ])->assertOk()
            ->assertJsonPath('data.slug', 'second-title');
    }

    public function test_super_admin_can_create_blog_category_and_use_it(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $createCategory = $this->postJson('/api/v1/admin/marketing-blog-categories', [
            'label' => 'Case studies',
        ]);
        $createCategory->assertCreated()
            ->assertJsonPath('data.label', 'Case studies');
        $slug = $createCategory->json('data.id');
        $this->assertNotEmpty($slug);

        $this->postJson('/api/v1/admin/marketing-blogs', [
            'title' => 'Retail rollout',
            'body' => '<p>A case study.</p>',
            'category' => $slug,
        ])->assertCreated()
            ->assertJsonPath('data.category', $slug);

        $this->getJson('/api/v1/admin/marketing-blog-categories')
            ->assertOk()
            ->assertJsonFragment(['id' => $slug, 'label' => 'Case studies']);
    }
}
