<?php

namespace Tests\Unit;

use App\Models\SystemIssueReport;
use App\Services\SystemIssues\SystemIssueAlertService;
use App\Services\SystemIssues\SystemIssueAlertSettingsResolver;
use App\Services\SystemIssues\SystemIssueFingerprint;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SystemIssueAlertServiceTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_should_send_instant_for_user_report_when_channel_enabled(): void
    {
        $org = SystemIssueAlertSettingsResolver::platformOrganization();
        $this->assertNotNull($org);

        SystemIssueAlertSettingsResolver::save([
            'whatsapp_instant_enabled' => true,
            'whatsapp_number' => '0712345678',
            'instant_email_enabled' => false,
            'email_digest_enabled' => true,
            'digest_email' => 'ops@example.com',
        ]);

        $report = SystemIssueReport::create([
            'organization_id' => $org->id,
            'user_id' => null,
            'kind' => 'user_report',
            'fingerprint' => SystemIssueFingerprint::forReport('user_report', 'UI is slow', '/sales'),
            'status' => 'open',
            'message' => 'UI is slow',
            'reported_by_user' => true,
        ]);

        $service = app(SystemIssueAlertService::class);
        $this->assertTrue($service->shouldSendInstant($report));
    }

    public function test_should_not_send_instant_when_channels_disabled(): void
    {
        SystemIssueAlertSettingsResolver::save([
            'whatsapp_instant_enabled' => false,
            'instant_email_enabled' => false,
            'whatsapp_number' => '0712345678',
            'digest_email' => 'ops@example.com',
        ]);

        $report = new SystemIssueReport([
            'kind' => 'user_report',
            'fingerprint' => 'abc',
            'message' => 'test',
            'reported_by_user' => true,
        ]);

        $this->assertFalse(app(SystemIssueAlertService::class)->shouldSendInstant($report));
    }
}
