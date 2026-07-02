<?php

namespace Tests\Unit;

use App\Services\Notifications\NotificationActionUrlBuilder;
use Tests\TestCase;

class NotificationActionUrlBuilderTest extends TestCase
{
    public function test_builds_deep_links_for_approval_types(): void
    {
        $this->assertSame(
            '/sales/returns?return_id=42',
            NotificationActionUrlBuilder::for('customer_return', 42),
        );
        $this->assertSame(
            '/hr/leave?leave_day_id=7',
            NotificationActionUrlBuilder::for('leave_request', 7),
        );
        $this->assertSame(
            '/suppliers/returns?return_id=9',
            NotificationActionUrlBuilder::for('supplier_return', 9),
        );
    }

    public function test_builds_absolute_frontend_url(): void
    {
        config(['erp.frontend_url' => 'http://localhost:3000']);

        $this->assertSame(
            'http://localhost:3000/sales/returns?return_id=5',
            NotificationActionUrlBuilder::absolute('/sales/returns?return_id=5'),
        );
    }
}
