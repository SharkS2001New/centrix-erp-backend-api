<?php

namespace Tests\Unit;

use App\Support\SalesOrderQueuePermissions;
use Tests\TestCase;

class SalesOrderQueuePermissionsTest extends TestCase
{
    public function test_permission_codes_follow_slug_pattern(): void
    {
        $this->assertSame('sales.order_queue_all.view', SalesOrderQueuePermissions::permissionCodeForSlug('all'));
        $this->assertSame('sales.order_queue_booked.view', SalesOrderQueuePermissions::permissionCodeForSlug('booked'));
        $this->assertSame('sales.order_queue_pending_payment.view', SalesOrderQueuePermissions::permissionCodeForSlug('pending_payment'));
    }

    public function test_registry_features_include_view_only_actions(): void
    {
        $features = SalesOrderQueuePermissions::registryFeatures();

        $this->assertArrayHasKey('order_queue_all', $features);
        $this->assertSame(['view'], $features['order_queue_all']['actions']);
        $this->assertArrayNotHasKey('orders', $features);
    }

    public function test_all_view_permission_codes_match_definitions(): void
    {
        $codes = SalesOrderQueuePermissions::allViewPermissionCodes();

        $this->assertCount(count(SalesOrderQueuePermissions::definitions()), $codes);
        $this->assertContains('sales.order_queue_mobile.view', $codes);
    }
}
