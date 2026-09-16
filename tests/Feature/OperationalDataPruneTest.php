<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\KraAgentCommand;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class OperationalDataPruneTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_prune_deletes_released_reservations_and_old_audit_logs(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $product = Product::query()->whereNull('deleted_at')->firstOrFail();
        $branchId = (int) $admin->branch_id;

        StockReservation::query()->create([
            'branch_id' => $branchId,
            'product_code' => $product->product_code,
            'stock_location' => 'shop',
            'quantity' => 2,
            'reserved_by' => $admin->id,
            'released_at' => now()->subDays(20),
            'expires_at' => now()->subDays(21),
        ]);

        AuditLog::query()->forceCreate([
            'user_id' => $admin->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $branchId,
            'action' => 'test',
            'table_name' => 'sales',
            'record_id' => '1',
            'created_at' => now()->subDays(15),
        ]);

        Artisan::call('erp:prune-operational-data');

        $this->assertSame(0, StockReservation::query()
            ->where('product_code', $product->product_code)
            ->where('quantity', 2)
            ->whereNotNull('released_at')
            ->count());

        $this->assertSame(0, AuditLog::query()
            ->where('action', 'test')
            ->where('table_name', 'sales')
            ->count());
    }

    public function test_prune_hard_deletes_old_cancelled_sales(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $template = Sale::query()->firstOrFail();

        $sale = Sale::create([
            'order_num' => 97001,
            'branch_id' => $admin->branch_id ?? $template->branch_id,
            'organization_id' => $admin->organization_id,
            'channel' => 'backend',
            'cashier_id' => $admin->id,
            'customer_num' => $template->customer_num,
            'status' => 'cancelled',
            'total_vat' => 0,
            'order_total' => 100,
            'payment_status' => 'unpaid',
            'amount_paid' => 0,
            'cancelled_at' => now()->subDays(10),
            'cancelled_by' => $admin->id,
        ]);

        StockReservation::query()->create([
            'branch_id' => (int) $sale->branch_id,
            'product_code' => Product::query()->firstOrFail()->product_code,
            'stock_location' => 'store',
            'quantity' => 1,
            'sale_id' => $sale->id,
            'reserved_by' => $admin->id,
            'released_at' => now()->subDay(),
        ]);

        Artisan::call('erp:prune-operational-data');

        $this->assertNull(Sale::query()->find($sale->id));
        $this->assertSame(0, StockReservation::query()->where('sale_id', $sale->id)->count());
    }

    public function test_prune_kra_commands_respect_completed_vs_failed_retention(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('kra_agent_commands')) {
            $this->markTestSkipped('kra_agent_commands missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $agentId = DB::table('kra_agents')->where('organization_id', $admin->organization_id)->value('id');
        if (! $agentId) {
            $agentId = DB::table('kra_agents')->insertGetId([
                'organization_id' => $admin->organization_id,
                'branch_id' => $admin->branch_id,
                'name' => 'test-agent',
                'comstore_base_url' => 'http://127.0.0.1:4000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $oldCompleted = (string) \Illuminate\Support\Str::uuid();
        $oldFailed = (string) \Illuminate\Support\Str::uuid();
        $recentCompleted = (string) \Illuminate\Support\Str::uuid();

        foreach ([
            [$oldCompleted, 'completed', now()->subDays(40)],
            [$oldFailed, 'failed', now()->subDays(10)],
            [$recentCompleted, 'completed', now()->subDays(5)],
        ] as [$id, $status, $when]) {
            KraAgentCommand::query()->create([
                'id' => $id,
                'kra_agent_id' => $agentId,
                'method' => 'POST',
                'path' => '/test',
                'body_json' => [],
                'status' => $status,
                'created_at' => $when,
                'completed_at' => $when,
                'expires_at' => $when->copy()->addHour(),
            ]);
        }

        Artisan::call('erp:prune-operational-data');

        $this->assertNull(KraAgentCommand::query()->find($oldCompleted));
        $this->assertNull(KraAgentCommand::query()->find($oldFailed));
        $this->assertNotNull(KraAgentCommand::query()->find($recentCompleted));
    }

    public function test_prune_hikvision_commands_deletes_old_completed_rows(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hikvision_agent_commands')) {
            $this->markTestSkipped('hikvision_agent_commands missing');
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('attendance_clock_devices')) {
            $this->markTestSkipped('attendance_clock_devices missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $device = \App\Models\AttendanceClockDevice::query()->create([
            'organization_id' => $admin->organization_id,
            'device_no' => 'PRUNE-HIK-'.uniqid(),
            'location' => 'Test',
            'is_active' => true,
            'provider' => 'hikvision',
        ]);

        $oldId = (string) \Illuminate\Support\Str::uuid();
        $recentId = (string) \Illuminate\Support\Str::uuid();
        $oldWhen = now()->subDays(3);
        $recentWhen = now()->subHours(6);

        foreach ([
            [$oldId, $oldWhen],
            [$recentId, $recentWhen],
        ] as [$id, $when]) {
            \App\Models\HikvisionAgentCommand::query()->create([
                'id' => $id,
                'attendance_clock_device_id' => $device->id,
                'method' => 'POST',
                'path' => '/ISAPI/AccessControl/AcsEvent?format=json',
                'body_json' => [],
                'status' => 'completed',
                'response_body' => str_repeat('x', 50_000),
                'created_at' => $when,
                'completed_at' => $when,
                'expires_at' => $when->copy()->addHour(),
            ]);
        }

        Artisan::call('erp:prune-operational-data');

        $this->assertNull(\App\Models\HikvisionAgentCommand::query()->find($oldId));
        $this->assertNotNull(\App\Models\HikvisionAgentCommand::query()->find($recentId));
    }

    public function test_prune_deletes_attendance_older_than_two_months(): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('employee_attendance')) {
            $this->markTestSkipped('employee_attendance missing');
        }

        $admin = User::where('username', 'admin')->firstOrFail();
        $employee = \App\Models\Employee::query()
            ->where('organization_id', $admin->organization_id)
            ->firstOrFail();

        $old = \App\Models\EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => now()->subDays(70)->toDateString(),
            'status' => 'present',
            'source' => 'clock_device',
            'hours_worked' => 8,
        ]);
        $recent = \App\Models\EmployeeAttendance::query()->create([
            'employee_id' => $employee->id,
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'attendance_date' => now()->subDays(10)->toDateString(),
            'status' => 'present',
            'source' => 'clock_device',
            'hours_worked' => 8,
        ]);

        $oldSession = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('employee_clock_sessions')) {
            $oldSession = \App\Models\EmployeeClockSession::query()->create([
                'employee_id' => $employee->id,
                'organization_id' => $admin->organization_id,
                'branch_id' => $admin->branch_id,
                'attendance_id' => $old->id,
                'clock_in_at' => now()->subDays(70)->setTime(8, 0),
                'clock_out_at' => now()->subDays(70)->setTime(17, 0),
                'source' => 'clock_device',
            ]);
        }

        Artisan::call('erp:prune-operational-data');

        $this->assertNull(\App\Models\EmployeeAttendance::query()->find($old->id));
        $this->assertNotNull(\App\Models\EmployeeAttendance::query()->find($recent->id));
        if ($oldSession) {
            $this->assertNull(\App\Models\EmployeeClockSession::query()->find($oldSession->id));
        }
    }

    public function test_slow_queries_endpoint_requires_super_admin_shape(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->getJson('/api/v1/admin/slow-queries')
            ->assertOk()
            ->assertJsonStructure(['available', 'queries']);
    }

    public function test_platform_operational_prune_status_and_dry_run(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());

        $this->getJson('/api/v1/admin/operational-prune')
            ->assertOk()
            ->assertJsonStructure([
                'retention' => ['attendance_days', 'hikvision_access_events_days'],
                'schedule_time',
                'tables',
            ]);

        $this->postJson('/api/v1/admin/operational-prune', [
            'dry_run' => true,
            'optimize_tables' => false,
        ])
            ->assertOk()
            ->assertJsonPath('dry_run', true)
            ->assertJsonStructure(['deleted', 'total', 'status']);
    }

    public function test_platform_operational_prune_forbidden_for_org_admin(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/admin/operational-prune')->assertForbidden();
        $this->postJson('/api/v1/admin/operational-prune', ['dry_run' => true])->assertForbidden();
    }
}
