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
}
