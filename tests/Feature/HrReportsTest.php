<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HrReportsTest extends TestCase
{
    use RefreshesErpDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($this->user);
    }

    public function test_hr_reports_catalog_lists_all_hr_reports(): void
    {
        $response = $this->getJson('/api/v1/reports/');
        $response->assertOk();

        $hr = $response->json('hr') ?? [];
        $keys = array_column($hr, 'key');

        $this->assertContains('leave-balance', $keys);
        $this->assertContains('payroll-summary', $keys);
        $this->assertContains('statutory-deductions', $keys);
        $this->assertContains('bank-transfer', $keys);
        $this->assertContains('nssf-remittance', $keys);
        $this->assertContains('other-deductions', $keys);
        $this->assertContains('staff-turnover', $keys);
        $this->assertContains('headcount', $keys);
        $this->assertContains('contract-expiry', $keys);
        $this->assertContains('hr-dashboard-kpi', $keys);
    }

    public function test_hr_user_can_access_leave_balance_report(): void
    {
        $this->getJson('/api/v1/reports/leave-balance')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_hr_user_can_access_headcount_report(): void
    {
        $this->getJson('/api/v1/reports/headcount')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_hr_user_can_access_nssf_remittance_report(): void
    {
        $this->getJson('/api/v1/reports/nssf-remittance')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }

    public function test_hr_user_can_access_other_deductions_report(): void
    {
        $this->getJson('/api/v1/reports/other-deductions')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page']);
    }
}
