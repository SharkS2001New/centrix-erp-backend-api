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
        $this->assertSame(
            '/expenses?expense_id=15',
            NotificationActionUrlBuilder::for('expense', 15),
        );
        $this->assertSame(
            '/expenses',
            NotificationActionUrlBuilder::for('expense', 0),
        );
        $this->assertSame(
            '/hr/cash-advances?advance_id=3',
            NotificationActionUrlBuilder::for('cash_advance', 3),
        );
        $this->assertSame(
            '/hr/pending-overtime?overtime_id=11',
            NotificationActionUrlBuilder::for('pending_overtime', 11),
        );
        $this->assertSame(
            '/sales/orders/queues/editable',
            NotificationActionUrlBuilder::discountEditableActionUrl(['channel' => 'mobile']),
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
