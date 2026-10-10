<?php

namespace Tests\Unit;

use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\IndustryRegistry;
use App\Services\Erp\SetupProfileMenuService;
use Tests\TestCase;

class DrivingIndustryTest extends TestCase
{
    public function test_driving_school_is_its_own_industry(): void
    {
        $ids = array_column(IndustryRegistry::optionsPayload(), 'id');

        $this->assertContains('driving', $ids);
        $this->assertSame('Driving School', IndustryRegistry::labelForIndustry('driving'));
        $this->assertSame('driving', IndustryRegistry::industryForProfile('driving_school'));
        $this->assertSame(
            ['driving', 'admin'],
            app(SetupProfileMenuService::class)->applicationIdsForIndustry('driving'),
        );
    }

    public function test_driving_school_setup_does_not_turn_on_retail_hotel_or_salon(): void
    {
        $modules = config('erp.profiles.driving_school.modules');
        $apps = app(ApplicationProvisioner::class)->applicationsFromProfileModules($modules);

        $this->assertTrue($apps['driving']);
        $this->assertTrue($apps['admin']);
        $this->assertFalse($apps['pos']);
        $this->assertFalse($apps['backoffice']);
        $this->assertFalse($apps['hotel_bar_pos']);
        $this->assertFalse($apps['hospitality_backoffice']);
        $this->assertFalse($apps['spa']);
    }
}
