<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformThemeColorsTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_save_and_clear_platform_theme_colors(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $this->patchJson('/api/v1/admin/platform-theme', [
            'classic_pos_theme_template' => 'rose',
            'classic_pos_theme_colors' => [
                'header' => '#BE185D',
                'button' => '#112233',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('classic_pos_theme_template', 'rose')
            ->assertJsonPath('classic_pos_theme_colors.header', '#be185d')
            ->assertJsonPath('classic_pos_theme_colors.button', '#112233');

        $platform = Organization::query()
            ->where('company_code', config('erp.platform_company_code', 'PLATFORM'))
            ->firstOrFail()
            ->fresh();
        $stored = $platform->module_settings['sales']['classic_pos_theme_colors'] ?? null;
        $this->assertSame('#be185d', $stored['header'] ?? null);
        $this->assertSame('#112233', $stored['button'] ?? null);

        $this->getJson('/api/v1/admin/platform-theme')
            ->assertOk()
            ->assertJsonPath('classic_pos_theme_colors.header', '#be185d');

        $this->patchJson('/api/v1/admin/platform-theme', [
            'classic_pos_theme_template' => 'centrix',
            'classic_pos_theme_colors' => [],
        ])
            ->assertOk()
            ->assertJsonPath('classic_pos_theme_colors', [])
            ->assertJsonPath('classic_pos_theme_template', 'centrix');

        $platform = $platform->fresh();
        $this->assertSame([], $platform->module_settings['sales']['classic_pos_theme_colors'] ?? null);
    }
}
