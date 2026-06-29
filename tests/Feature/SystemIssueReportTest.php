<?php

namespace Tests\Feature;

use App\Models\SystemIssueReport;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class SystemIssueReportTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_list_system_issue_reports_with_user_relation(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $admin = User::where('username', 'admin')->firstOrFail();

        SystemIssueReport::create([
            'organization_id' => $admin->organization_id,
            'user_id' => $admin->id,
            'kind' => 'error',
            'fingerprint' => \App\Services\SystemIssues\SystemIssueFingerprint::forReport(
                'error',
                'Test API failure',
                '/api/v1/customer-returns',
            ),
            'status' => 'open',
            'message' => 'Test API failure',
            'page_url' => '/sales/returns',
            'api_path' => '/api/v1/customer-returns',
            'http_method' => 'GET',
            'http_status' => 500,
        ]);

        $this->getJson('/api/v1/admin/system-issue-reports?status=open&kind=all&page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.0.message', 'Test API failure')
            ->assertJsonPath('data.0.user.username', 'admin')
            ->assertJsonStructure([
                'data' => [
                    [
                        'id',
                        'kind',
                        'status',
                        'message',
                        'user' => ['id', 'username', 'full_name'],
                        'organization' => ['id', 'org_name', 'company_code'],
                    ],
                ],
            ]);
    }

    public function test_super_admin_can_mark_issue_resolved_and_summary_includes_resolved_count(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $report = SystemIssueReport::create([
            'organization_id' => null,
            'user_id' => $superAdmin->id,
            'kind' => 'error',
            'fingerprint' => \App\Services\SystemIssues\SystemIssueFingerprint::forReport('error', 'Resolve me', '/api/v1/test'),
            'status' => 'open',
            'message' => 'Resolve me',
        ]);

        $this->patchJson("/api/v1/admin/system-issue-reports/{$report->id}", [
            'status' => 'resolved',
        ])->assertOk()->assertJsonPath('status', 'resolved');

        $this->getJson('/api/v1/admin/system-issue-reports/summary')
            ->assertOk()
            ->assertJsonStructure(['open', 'acknowledged', 'resolved', 'today', 'high_priority']);
    }

    public function test_repetitive_issues_surface_as_high_priority(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $fingerprint = \App\Services\SystemIssues\SystemIssueFingerprint::forReport(
            'error',
            'Repeated failure',
            '/api/v1/sales',
        );

        for ($i = 0; $i < 3; $i++) {
            SystemIssueReport::create([
                'organization_id' => null,
                'user_id' => $superAdmin->id,
                'kind' => 'error',
                'fingerprint' => $fingerprint,
                'status' => 'open',
                'message' => 'Repeated failure',
                'api_path' => '/api/v1/sales',
            ]);
        }

        $this->getJson('/api/v1/admin/system-issue-reports?priority=high&status=open&page=1&per_page=25')
            ->assertOk()
            ->assertJsonPath('data.0.is_high_priority', true)
            ->assertJsonPath('data.0.occurrence_count', 3);
    }
}
