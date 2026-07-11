<?php

namespace Tests\Feature;

use App\Models\SystemIssueReport;
use App\Models\User;
use App\Services\SystemIssues\SystemIssueFingerprint;
use Carbon\Carbon;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PruneSystemIssueReportsCommandTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_prunes_reports_older_than_retention_days(): void
    {
        $user = User::where('username', 'superadmin')->firstOrFail();

        $old = SystemIssueReport::create([
            'organization_id' => null,
            'user_id' => $user->id,
            'kind' => 'error',
            'fingerprint' => SystemIssueFingerprint::forReport('error', 'Old issue', '/api/v1/old'),
            'status' => 'open',
            'message' => 'Old issue',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(45)])->save();

        $recent = SystemIssueReport::create([
            'organization_id' => null,
            'user_id' => $user->id,
            'kind' => 'report',
            'fingerprint' => SystemIssueFingerprint::forReport('report', 'Recent issue', null),
            'status' => 'open',
            'message' => 'Recent issue',
        ]);
        $recent->forceFill(['created_at' => Carbon::now()->subDays(5)])->save();

        $this->artisan('erp:prune-system-issue-reports')
            ->assertSuccessful();

        $this->assertDatabaseMissing('system_issue_reports', ['id' => $old->id]);
        $this->assertDatabaseHas('system_issue_reports', ['id' => $recent->id]);
    }

    public function test_dry_run_does_not_delete(): void
    {
        $user = User::where('username', 'superadmin')->firstOrFail();

        $old = SystemIssueReport::create([
            'organization_id' => null,
            'user_id' => $user->id,
            'kind' => 'error',
            'fingerprint' => SystemIssueFingerprint::forReport('error', 'Dry run old', '/api/v1/dry'),
            'status' => 'resolved',
            'message' => 'Dry run old',
        ]);
        $old->forceFill(['created_at' => Carbon::now()->subDays(40)])->save();

        $this->artisan('erp:prune-system-issue-reports', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertDatabaseHas('system_issue_reports', ['id' => $old->id]);
    }
}
