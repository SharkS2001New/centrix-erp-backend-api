<?php

namespace Tests\Unit;

use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\SetupProfileMenuService;
use Tests\TestCase;

class SpaIndustryTest extends TestCase
{
    public function test_spa_and_kinyozi_is_an_industry_with_its_own_setup(): void
    {
        $ids = array_column(IndustryRegistry::optionsPayload(), 'id');

        $this->assertContains('spa', $ids);
        $this->assertSame('SPA & Kinyozi', IndustryRegistry::labelForIndustry('spa'));
        $this->assertSame('spa', IndustryRegistry::industryForProfile('spa_kinyozi'));
        $this->assertSame(
            ['spa', 'admin'],
            app(SetupProfileMenuService::class)->applicationIdsForIndustry('spa'),
        );
        $this->assertSame(
            ['SPA / Salon / Kinyozi', 'Barbershop'],
            app(SetupProfileMenuService::class)->businessTypesForProfile('spa_kinyozi'),
        );
    }

    public function test_spa_setup_does_not_turn_on_retail_or_hotel_tills(): void
    {
        $modules = config('erp.profiles.spa_kinyozi.modules');
        $apps = app(ApplicationProvisioner::class)->applicationsFromProfileModules($modules);

        $this->assertTrue($apps['spa']);
        $this->assertTrue($apps['admin']);
        $this->assertFalse($apps['pos']);
        $this->assertFalse($apps['backoffice']);
        $this->assertFalse($apps['hotel_bar_pos']);
        $this->assertFalse($apps['hospitality_backoffice']);
    }
}
