<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\BackgroundTask;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\Background\BackgroundTaskService;
use App\Services\Customers\CustomerNumberAllocator;
use App\Services\Customers\CustomerUniquenessValidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ImportCustomersTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function enableAdvancedDataImportForDemoOrg(): void
    {
        $org = Organization::query()->where('company_code', 'DEMO')->firstOrFail();
        $settings = $org->module_settings ?? [];
        $settings['admin'] = array_merge($settings['admin'] ?? [], [
            'enable_advanced_data_import' => true,
        ]);
        $org->update(['module_settings' => $settings]);
    }

    public function test_customer_import_assigns_branch_and_creates_rows(): void
    {
        $this->enableAdvancedDataImportForDemoOrg();

        $admin = User::where('username', 'admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $route = RouteModel::query()->firstOrFail();

        $response = $this->postJson('/api/v1/customers/import-batch', [
            'rows' => [
                [
                    'customer_name' => 'Imported Route Customer',
                    'customer_type' => 'route',
                    'phone_number' => '0711000001',
                    'town' => 'Nairobi',
                    'route_id' => $route->id,
                ],
                [
                    'customer_name' => 'Imported Debtor Customer',
                    'customer_type' => 'debtor',
                    'phone_number' => '0711000002',
                    'town' => 'Nairobi',
                ],
            ],
        ])->assertAccepted();

        $taskId = (string) $response->json('task_id');
        $this->assertNotSame('', $taskId);

        (new ImportCustomersJob($taskId))->handle(
            app(BackgroundTaskService::class),
            app(CustomerUniquenessValidator::class),
            app(UserAccessService::class),
            app(CustomerNumberAllocator::class),
        );

        $task = BackgroundTask::query()->findOrFail($taskId);
        $this->assertSame('completed', $task->status);
        $this->assertSame(2, $task->result['created'] ?? null);

        $routeCustomer = Customer::query()
            ->where('customer_name', 'Imported Route Customer')
            ->firstOrFail();
        $debtorCustomer = Customer::query()
            ->where('customer_name', 'Imported Debtor Customer')
            ->firstOrFail();

        $this->assertNotNull($routeCustomer->branch_id);
        $this->assertSame((int) $route->id, (int) $routeCustomer->route_id);
        $this->assertNotNull($debtorCustomer->branch_id);
        $this->assertNull($debtorCustomer->route_id);
    }

    public function test_customer_import_reports_missing_route_without_failing_entire_job(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $task = BackgroundTask::createPending('customer_import', (int) $admin->organization_id, (int) $admin->id, [
            'rows' => [
                [
                    'customer_name' => 'Missing Route Customer',
                    'customer_type' => 'route',
                    'phone_number' => '0711000003',
                    'route_id' => 999999,
                ],
            ],
        ]);

        $job = new ImportCustomersJob($task->id);
        $job->handle(
            app(BackgroundTaskService::class),
            app(CustomerUniquenessValidator::class),
            app(UserAccessService::class),
            app(CustomerNumberAllocator::class),
        );

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(0, $task->result['created'] ?? null);
        $this->assertSame(1, $task->result['failed'] ?? null);
        $this->assertStringContainsString('Route ID 999999 does not exist', $task->result['failures'][0]['message'] ?? '');
    }

    public function test_customer_import_allocates_sequential_numbers_in_bulk(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $organizationId = (int) $admin->organization_id;
        $existingMax = (int) (Customer::query()
            ->where('organization_id', $organizationId)
            ->max('customer_num') ?? 0);

        $rows = [];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = [
                'customer_name' => "Bulk Import Customer {$i}",
                'customer_type' => 'debtor',
                'phone_number' => '0799'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ];
        }

        $task = BackgroundTask::createPending('customer_import', $organizationId, (int) $admin->id, [
            'rows' => $rows,
        ]);

        (new ImportCustomersJob($task->id))->handle(
            app(BackgroundTaskService::class),
            app(CustomerUniquenessValidator::class),
            app(UserAccessService::class),
            app(CustomerNumberAllocator::class),
        );

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(5, $task->result['created'] ?? null);

        $nums = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereIn('customer_name', array_column($rows, 'customer_name'))
            ->orderBy('customer_num')
            ->pluck('customer_num')
            ->map(fn ($num) => (int) $num)
            ->all();

        $this->assertSame(range($existingMax + 1, $existingMax + 5), $nums);
    }

    public function test_non_admin_with_customers_manage_can_import_when_advanced_import_enabled(): void
    {
        $this->enableAdvancedDataImportForDemoOrg();

        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::create([
            'role_name' => 'Customer Importer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $createId = (int) Permission::where('permission_code', 'customers.customers.create')->value('id');
        $this->assertGreaterThan(0, $createId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $createId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'customer_importer',
            'password' => Hash::make('password'),
            'full_name' => 'Customer Importer',
            'access_scope' => 'branch',
            'is_active' => true,
            'is_admin' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customers/import-batch', [
            'rows' => [
                [
                    'customer_name' => 'Permissioned Import Customer',
                    'customer_type' => 'debtor',
                    'phone_number' => '0711000099',
                    'town' => 'Nairobi',
                ],
            ],
        ])->assertAccepted();
    }

    public function test_non_admin_without_customers_manage_cannot_import(): void
    {
        $this->enableAdvancedDataImportForDemoOrg();

        $admin = User::where('username', 'admin')->firstOrFail();

        $role = Role::create([
            'role_name' => 'Customer Viewer',
            'scope' => 'branch',
            'is_active' => true,
        ]);

        $viewId = (int) Permission::where('permission_code', 'customers.customers.view')->value('id');
        $this->assertGreaterThan(0, $viewId);

        DB::table('role_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $viewId,
        ]);

        $user = User::create([
            'organization_id' => $admin->organization_id,
            'branch_id' => $admin->branch_id,
            'role_id' => $role->id,
            'username' => 'no_import_user',
            'password' => Hash::make('password'),
            'full_name' => 'No Import User',
            'access_scope' => 'branch',
            'is_active' => true,
            'is_admin' => false,
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/customers/import-batch', [
            'rows' => [
                [
                    'customer_name' => 'Blocked Import Customer',
                    'customer_type' => 'debtor',
                    'phone_number' => '0711000088',
                    'town' => 'Nairobi',
                ],
            ],
        ])->assertForbidden();
    }
}
