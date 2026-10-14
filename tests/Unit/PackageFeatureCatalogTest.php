<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Platform\PackageFeatureCatalog;
use Tests\TestCase;

class PackageFeatureCatalogTest extends TestCase
{
    public function test_commerce_packages_can_sell_external_pos_mobile_and_manager(): void
    {
        $ids = array_column((new PackageFeatureCatalog())->forIndustry('commerce'), 'id');

        $this->assertContains('pos', $ids);
        $this->assertContains('mobile', $ids);
        $this->assertContains('manager', $ids);
        $this->assertNotContains('hotel_bar_pos', $ids);
    }

    public function test_features_of_an_unchecked_module_are_dropped(): void
    {
        $normalized = (new PackageFeatureCatalog())->normalize('commerce', ['mobile'], [
            'mobile' => ['mobile_sales.dashboard'],
            'pos' => ['pos.terminal'],
        ]);

        $this->assertSame(['mobile' => ['mobile_sales.dashboard']], $normalized);
    }

    public function test_backoffice_features_open_the_matching_sections(): void
    {
        $sections = (new PackageFeatureCatalog())->backofficeSections([
            'backoffice' => ['catalogue.products', 'reports.daily_sales'],
        ]);

        $this->assertContains('products', $sections);
        $this->assertContains('reports', $sections);
        $this->assertNotContains('inventory', $sections);
    }

    public function test_unpaid_permission_features_are_turned_off(): void
    {
        $org = new Organization();
        $org->module_settings = [
            'licence' => [
                'features' => [
                    'mobile' => ['mobile_sales.dashboard'],
                ],
            ],
        ];

        $map = (new PackageFeatureCatalog())->filterPermissionMap([
            'mobile_sales.dashboard.view' => true,
            'mobile_sales.orders.view' => true,
            'sales.create' => true,
        ], $org);

        $this->assertTrue($map['mobile_sales.dashboard.view']);
        $this->assertFalse($map['mobile_sales.orders.view']);
        $this->assertTrue($map['sales.create']);
    }
}
