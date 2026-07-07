<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Sales\DiscountApprovalService;
use Tests\TestCase;

class DiscountApprovalServiceTest extends TestCase
{
    public function test_discount_percent_calculation(): void
    {
        $service = app(DiscountApprovalService::class);

        $this->assertSame(20.0, $service->discountPercent(200, 1000));
        $this->assertSame(0.0, $service->discountPercent(0, 1000));
        $this->assertSame(0.0, $service->discountPercent(100, 0));
    }

    public function test_threshold_from_settings(): void
    {
        $service = app(DiscountApprovalService::class);

        $this->assertSame(10.0, $service->thresholdPercent(['discount_approval_threshold_percent' => 10]));
        $this->assertSame(100.0, $service->thresholdPercent(['discount_approval_threshold_percent' => 150]));
    }

    public function test_approval_mode_unlocks_manual_discounts_without_base_settings(): void
    {
        $service = app(DiscountApprovalService::class);
        $base = [
            'allow_edit_line_discount' => false,
            'enable_order_discount' => false,
            'allow_discounts' => false,
            'discount_approval_enabled' => true,
        ];

        $this->assertTrue($service->allowsManualLineDiscount($base));
        $this->assertFalse($service->allowsOrderDiscount($base));
        $this->assertTrue($service->allowsLineDiscountAmount($base));
    }

    public function test_disabled_approval_uses_base_discount_settings(): void
    {
        $service = app(DiscountApprovalService::class);
        $base = [
            'allow_edit_line_discount' => false,
            'allow_pos_edit_line_discount' => false,
            'enable_order_discount' => false,
            'allow_discounts' => true,
            'discount_approval_enabled' => false,
        ];

        $this->assertFalse($service->allowsManualLineDiscount($base));
        $this->assertFalse($service->allowsManualLineDiscount($base, 'pos'));
        $this->assertFalse($service->allowsOrderDiscount($base));
        $this->assertTrue($service->allowsLineDiscountAmount($base));
    }

    public function test_manual_line_discount_is_channel_specific_without_approval(): void
    {
        $service = app(DiscountApprovalService::class);
        $base = [
            'allow_edit_line_discount' => true,
            'allow_pos_edit_line_discount' => false,
            'discount_approval_enabled' => false,
        ];

        $this->assertTrue($service->allowsManualLineDiscount($base, 'backoffice'));
        $this->assertFalse($service->allowsManualLineDiscount($base, 'pos'));

        $base['allow_edit_line_discount'] = false;
        $base['allow_pos_edit_line_discount'] = true;

        $this->assertFalse($service->allowsManualLineDiscount($base, 'backoffice'));
        $this->assertTrue($service->allowsManualLineDiscount($base, 'pos'));
    }

    public function test_order_discount_disabled_for_staff_in_approval_mode(): void
    {
        $this->mock(\App\Services\Auth\UserPermissionService::class, function ($mock) {
            $mock->shouldReceive('canGiveDiscountDirectly')
                ->andReturnUsing(fn (User $user) => (bool) $user->is_admin);
        });

        $service = app(DiscountApprovalService::class);
        $settings = [
            'enable_order_discount' => true,
            'discount_approval_enabled' => true,
        ];

        $admin = new User(['is_admin' => true]);
        $staff = new User(['is_admin' => false, 'id' => 2, 'organization_id' => 1]);

        $this->assertTrue($service->requiresDiscountRequestWorkflow($settings, $staff));
        $this->assertFalse($service->requiresDiscountRequestWorkflow($settings, $admin));
        $this->assertTrue($service->allowsOrderDiscount($settings, $admin));
        $this->assertFalse($service->allowsOrderDiscount($settings, $staff));
    }
}
