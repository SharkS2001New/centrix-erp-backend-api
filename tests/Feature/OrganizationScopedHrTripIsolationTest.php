<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\DriverController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\PayrollRunController;
use App\Http\Controllers\Api\V1\RouteScheduleController;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Employee;
use App\Models\Organization;
use App\Models\PayPeriod;
use App\Models\PayrollRun;
use App\Models\RouteSchedule;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OrganizationScopedHrTripIsolationTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_employee_show_is_organization_scoped(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $foreign = $this->createForeignOrgEmployee();

        $request = Request::create('/api/v1/employees/'.$foreign->id, 'GET');
        $request->setUserResolver(fn () => $admin);

        $this->expectException(ModelNotFoundException::class);
        app(EmployeeController::class)->show($request, (string) $foreign->id);
    }

    public function test_payroll_run_show_is_organization_scoped(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $foreignOrg = $this->createForeignOrganization();
        $period = PayPeriod::create([
            'organization_id' => $foreignOrg->id,
            'period_code' => 'FOREIGN-'.uniqid(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'pay_date' => now()->endOfMonth()->toDateString(),
        ]);
        $run = PayrollRun::create([
            'organization_id' => $foreignOrg->id,
            'pay_period_id' => $period->id,
            'run_date' => now()->toDateString(),
            'status' => 'draft',
            'total_gross' => 0,
            'total_net' => 0,
        ]);

        $request = Request::create('/api/v1/payroll-runs/'.$run->id, 'GET');
        $request->setUserResolver(fn () => $admin);

        $this->expectException(ModelNotFoundException::class);
        app(PayrollRunController::class)->show($request, (string) $run->id);
    }

    public function test_driver_create_stamps_organization_and_rejects_foreign_branch(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $foreignOrg = $this->createForeignOrganization();
        $foreignBranch = Branch::create([
            'organization_id' => $foreignOrg->id,
            'branch_code' => 'FOREIGN-DRV',
            'branch_name' => 'Foreign Driver Branch',
            'is_active' => true,
        ]);

        $access = app(UserAccessService::class);
        try {
            $access->assertBranchInOrganization($admin, (int) $foreignBranch->id);
            $this->fail('Expected foreign branch to be rejected.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $okRequest = Request::create('/api/v1/drivers', 'POST', [
            'branch_id' => $admin->branch_id,
            'driver_code' => 'DRV-OK-'.uniqid(),
            'full_name' => 'Local Driver',
            'is_active' => true,
        ]);
        $okRequest->setUserResolver(fn () => $admin);

        $response = app(DriverController::class)->store($okRequest);
        $payload = $response->getData(true);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame((int) $admin->organization_id, (int) $payload['organization_id']);
        $this->assertDatabaseHas('drivers', [
            'id' => $payload['id'],
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
        ]);
    }

    public function test_route_schedules_for_date_does_not_leak_across_orgs(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $foreignOrg = $this->createForeignOrganization();
        $foreignBranch = Branch::create([
            'organization_id' => $foreignOrg->id,
            'branch_code' => 'FOREIGN-RS',
            'branch_name' => 'Foreign Route Branch',
            'is_active' => true,
        ]);

        // Seed a foreign schedule only if routes are not tightly org-FK'd in this env.
        $foreignRouteId = (int) (\App\Models\RouteModel::query()
            ->where('organization_id', $foreignOrg->id)
            ->value('id') ?? 0);
        if ($foreignRouteId > 0) {
            RouteSchedule::create([
                'organization_id' => $foreignOrg->id,
                'branch_id' => $foreignBranch->id,
                'route_id' => $foreignRouteId,
                'day_of_week' => (int) now()->format('w'),
                'is_active' => true,
            ]);
        }

        $request = Request::create('/api/v1/route-schedules/for-date', 'GET', [
            'date' => now()->toDateString(),
        ]);
        $request->setUserResolver(fn () => $admin);

        $response = app(RouteScheduleController::class)->forDate($request);
        $payload = $response->getData(true);

        $this->assertIsArray($payload['schedules'] ?? null);
        foreach ($payload['schedules'] as $row) {
            if (! empty($row['organization_id'])) {
                $this->assertSame((int) $admin->organization_id, (int) $row['organization_id']);
            }
            if (! empty($row['branch_id'])) {
                $this->assertNotSame((int) $foreignBranch->id, (int) $row['branch_id']);
            }
        }

        $query = RouteSchedule::query()->where('is_active', true);
        app(UserAccessService::class)->scopeOrganization($query, $admin);
        $this->assertFalse(
            $query->where('branch_id', $foreignBranch->id)->exists(),
            'Org-scoped route schedule query must not include foreign branch schedules.',
        );
    }

    protected function createForeignOrganization(): Organization
    {
        return Organization::create([
            'company_code' => 'ORGHR'.substr(uniqid(), -6),
            'org_name' => 'Org HR Isolation',
            'org_email' => 'orghr'.uniqid().'@test.com',
            'primary_tel' => '0700444001',
            'org_address' => 'Nairobi',
            'deployment_profile' => 'wholesale_retail',
        ]);
    }

    protected function createForeignOrgEmployee(): Employee
    {
        $org = $this->createForeignOrganization();
        $branch = Branch::create([
            'organization_id' => $org->id,
            'branch_code' => 'FOREIGN-EMP',
            'branch_name' => 'Foreign Emp Branch',
            'is_active' => true,
        ]);

        return Employee::create([
            'organization_id' => $org->id,
            'branch_id' => $branch->id,
            'employee_code' => 'EMP-X-'.uniqid(),
            'payroll_number' => 'PAY-X-'.uniqid(),
            'first_name' => 'Foreign',
            'last_name' => 'Employee',
            'full_name' => 'Foreign Employee',
            'employment_status' => 'active',
            'is_active' => true,
            'base_salary' => 0,
        ]);
    }
}
