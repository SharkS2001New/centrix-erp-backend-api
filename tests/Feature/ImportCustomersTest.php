<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\BackgroundTask;
use App\Models\Customer;
use App\Models\RouteModel;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ImportCustomersTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_customer_import_assigns_branch_and_creates_rows(): void
    {
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
            app(\App\Services\Customers\CustomerUniquenessValidator::class),
            app(\App\Services\Auth\UserAccessService::class),
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
            app(\App\Services\Customers\CustomerUniquenessValidator::class),
            app(\App\Services\Auth\UserAccessService::class),
        );

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(0, $task->result['created'] ?? null);
        $this->assertSame(1, $task->result['failed'] ?? null);
        $this->assertStringContainsString('Route ID 999999 does not exist', $task->result['failures'][0]['message'] ?? '');
    }
}
