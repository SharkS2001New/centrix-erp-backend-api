<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Auth\UserLoginChannelService;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class UserLoginChannelServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_mobile_channel_allows_notifications_and_issue_reports(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/notifications/unread-count'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/system-issue-reports'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/action-requests/pending'));
    }

    public function test_mobile_channel_allows_sales_report_paths_only(): void
    {
        $user = User::where('username', 'admin')->firstOrFail();
        $service = app(UserLoginChannelService::class);

        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/reports/sales-by-supplier'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/reports/sales-by-product'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/reports/sales-by-user'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/reports/daily-sales'));

        // AS shop supplier info / ledger / payments / purchases.
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/suppliers'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/suppliers/12'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/suppliers/12/summary'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/supplier-payments'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/reference/suppliers'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/inventory/receive'));
        $this->assertTrue($service->mobileTokenCanAccessPath($user, 'api/v1/stock-receipts'));

        // Full reports suite stays manager-only.
        $this->assertFalse($service->mobileTokenCanAccessPath($user, 'api/v1/reports/profit-loss'));
        $this->assertFalse($service->mobileTokenCanAccessPath($user, 'api/v1/reports/eod-report'));
    }
}
