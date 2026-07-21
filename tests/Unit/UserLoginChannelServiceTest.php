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
}
