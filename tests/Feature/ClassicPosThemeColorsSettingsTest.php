<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ClassicPosThemeColorsSettingsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function seedLicense(User $user): void
    {
        PlatformSubscription::query()->firstOrCreate(
            ['organization_id' => $user->organization_id],
            [
                'status' => 'active',
                'seat_count' => 5,
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addYear()->toDateString(),
                'is_trial' => false,
            ],
        );
    }

    public function test_org_admin_can_save_and_clear_classic_pos_theme_colors(): void
    {
        $orgAdmin = User::where('username', 'admin')->firstOrFail();
        $this->seedLicense($orgAdmin);
        Sanctum::actingAs($orgAdmin);

        $this->patchJson('/api/v1/erp/settings/sales', [
            'classic_pos_theme_template' => 'rose',
            'classic_pos_theme_colors' => [
                'header' => '#BE185D',
                'button' => '#112233',
                'workspace' => '#CDB48B',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('sales.classic_pos_theme_template', 'rose')
            ->assertJsonPath('sales.classic_pos_theme_colors.header', '#be185d')
            ->assertJsonPath('sales.classic_pos_theme_colors.button', '#112233')
            ->assertJsonPath('sales.classic_pos_theme_colors.workspace', '#cdb48b');

        $org = Organization::findOrFail($orgAdmin->organization_id)->fresh();
        $stored = $org->module_settings['sales']['classic_pos_theme_colors'] ?? null;
        $this->assertIsArray($stored);
        $this->assertSame('#be185d', $stored['header'] ?? null);
        $this->assertSame('#112233', $stored['button'] ?? null);
        $this->assertSame('#cdb48b', $stored['workspace'] ?? null);

        $this->getJson('/api/v1/erp/settings/sales')
            ->assertOk()
            ->assertJsonPath('sales.classic_pos_theme_colors.header', '#be185d')
            ->assertJsonPath('sales.classic_pos_theme_template', 'rose');

        $this->patchJson('/api/v1/erp/settings/sales', [
            'classic_pos_theme_template' => 'rose',
            'classic_pos_theme_colors' => [
                'header' => '#BE185D',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('sales.classic_pos_theme_colors.header', '#be185d');

        $partial = $this->getJson('/api/v1/erp/settings/sales')->assertOk()->json('sales.classic_pos_theme_colors');
        $this->assertSame(['header' => '#be185d'], $partial);

        $this->patchJson('/api/v1/erp/settings/sales', [
            'classic_pos_theme_template' => 'centrix',
            'classic_pos_theme_colors' => [],
        ])
            ->assertOk()
            ->assertJsonPath('sales.classic_pos_theme_colors', [])
            ->assertJsonPath('sales.classic_pos_theme_template', 'centrix');

        $org = Organization::findOrFail($orgAdmin->organization_id)->fresh();
        $this->assertSame([], $org->module_settings['sales']['classic_pos_theme_colors'] ?? null);
    }
}
