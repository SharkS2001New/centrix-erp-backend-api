<?php

namespace Tests\Feature;

use App\Models\PlatformPlan;
use App\Services\Platform\FreePlanAccess;
use App\Services\Platform\PlatformPaybillLicenceService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PublicSelfServePlansTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_catalogue_includes_the_configured_free_plan_name(): void
    {
        $settings = app(PlatformPaybillLicenceService::class)->saveSettings([]);
        app(FreePlanAccess::class)->persist($settings, ['name' => 'Free Pack']);

        PlatformPlan::query()->create([
            'name' => 'Retail Starter',
            'code' => 'RETAIL-STARTER',
            'monthly_price' => 1500,
            'self_serve' => true,
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/public/self-serve-plans')
            ->assertOk()
            ->assertJsonPath('free_plan_name', 'Free Pack')
            ->assertJsonPath('data.0.name', 'Retail Starter');
    }
}
