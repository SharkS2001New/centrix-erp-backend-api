<?php

namespace Tests\Unit;

use App\Models\PlatformSubscription;
use App\Services\Platform\OrganizationLicenseService;
use Carbon\Carbon;
use Tests\TestCase;

class LicencePeriodWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_licence_opens_and_closes_on_the_hour(): void
    {
        $service = app(OrganizationLicenseService::class);
        $sub = new PlatformSubscription([
            'status' => 'active',
            'current_period_start' => '2026-10-10 14:00:00',
            'current_period_end' => '2026-10-10 15:00:00',
        ]);

        Carbon::setTestNow('2026-10-10 13:30:00');
        $before = $service->periodWindow($sub);
        $this->assertSame('scheduled', $before['status']);

        Carbon::setTestNow('2026-10-10 14:30:00');
        $open = $service->periodWindow($sub);
        $this->assertSame('active', $open['status']);
        $this->assertGreaterThan(0, $open['seconds_remaining']);

        Carbon::setTestNow('2026-10-10 15:00:00');
        $closed = $service->periodWindow($sub);
        $this->assertSame('expired', $closed['status']);
        $this->assertLessThanOrEqual(0, $closed['seconds_remaining']);
    }

    public function test_restoring_a_revoked_licence_follows_the_end_hour(): void
    {
        $service = app(OrganizationLicenseService::class);
        $sub = new PlatformSubscription([
            'status' => 'cancelled',
            'is_trial' => false,
            'current_period_end' => '2026-10-10 18:00:00',
        ]);

        Carbon::setTestNow('2026-10-10 17:00:00');
        $this->assertSame('active', $service->restoredStatus($sub));

        Carbon::setTestNow('2026-10-10 18:00:00');
        $this->assertSame('expired', $service->restoredStatus($sub));
    }
}
