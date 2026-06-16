<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeKpi;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HrDepartmentKpiTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_admin_can_manage_departments(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/departments', [
            'department_name' => 'Operations',
            'department_code' => 'OPS',
        ])
            ->assertCreated()
            ->assertJsonPath('department_name', 'Operations');

        $deptId = Department::where('department_code', 'OPS')->value('id');

        $this->putJson("/api/v1/departments/{$deptId}", [
            'department_name' => 'Operations & Logistics',
        ])
            ->assertOk()
            ->assertJsonPath('department_name', 'Operations & Logistics');

        $this->getJson('/api/v1/departments?per_page=50')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_admin_can_view_and_manage_employee_kpis(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $employee = Employee::firstOrFail();

        $this->getJson("/api/v1/employees/{$employee->id}/kpis")
            ->assertOk()
            ->assertJsonStructure([
                'computed' => [['key', 'label', 'value', 'unit']],
                'tracked',
            ]);

        $this->postJson("/api/v1/employees/{$employee->id}/kpis", [
            'label' => 'Monthly sales target',
            'target_value' => 100000,
            'actual_value' => 85000,
            'unit' => 'KES',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
        ])
            ->assertCreated()
            ->assertJsonPath('label', 'Monthly sales target')
            ->assertJsonPath('progress_pct', 85);

        $kpiId = EmployeeKpi::where('employee_id', $employee->id)->value('id');

        $this->putJson("/api/v1/employees/{$employee->id}/kpis/{$kpiId}", [
            'actual_value' => 95000,
        ])
            ->assertOk()
            ->assertJsonPath('progress_pct', 95);

        $this->deleteJson("/api/v1/employees/{$employee->id}/kpis/{$kpiId}")
            ->assertNoContent();
    }

    public function test_cashier_cannot_manage_departments_or_kpis(): void
    {
        $cashier = User::where('username', 'cashier')->firstOrFail();
        Sanctum::actingAs($cashier);

        $this->postJson('/api/v1/departments', [
            'department_name' => 'Blocked Dept',
        ])->assertForbidden();

        $employee = Employee::firstOrFail();

        $this->getJson("/api/v1/employees/{$employee->id}/kpis")->assertForbidden();

        $this->postJson("/api/v1/employees/{$employee->id}/kpis", [
            'label' => 'Should fail',
        ])->assertForbidden();

        $this->getJson('/api/v1/organization-kpis')->assertForbidden();
    }

    public function test_admin_can_manage_organization_kpis_and_track_achievement(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/v1/organization-kpis', [
            'label' => 'Monthly attendance rate',
            'target_value' => 95,
            'unit' => '%',
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'assign_to_active' => true,
        ])->assertCreated();

        $orgKpiId = $create->json('kpi.id');
        $this->assertNotNull($orgKpiId);
        $this->assertGreaterThan(0, $create->json('assigned_count'));

        $employee = Employee::where('employment_status', 'active')->where('is_active', true)->firstOrFail();
        $employeeKpi = EmployeeKpi::where('organization_kpi_id', $orgKpiId)
            ->where('employee_id', $employee->id)
            ->firstOrFail();

        $this->putJson("/api/v1/employees/{$employee->id}/kpis/{$employeeKpi->id}", [
            'actual_value' => 100,
        ])->assertOk();

        $achievement = $this->getJson("/api/v1/organization-kpis/{$orgKpiId}/achievement")
            ->assertOk()
            ->assertJsonStructure(['kpi', 'employees', 'summary']);

        $this->assertGreaterThanOrEqual(1, $achievement->json('summary.met'));

        $this->getJson('/api/v1/organization-kpis')
            ->assertOk()
            ->assertJsonPath('data.0.label', 'Monthly attendance rate');
    }
}
