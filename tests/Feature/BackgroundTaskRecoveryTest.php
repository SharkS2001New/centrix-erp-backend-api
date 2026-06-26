<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportExportJob;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackgroundTaskRecoveryTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_stale_running_task_is_recovered_before_new_export(): void
    {
        Queue::fake();

        $admin = User::where('username', 'admin')->firstOrFail();

        BackgroundTask::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => (int) $admin->organization_id,
            'user_id' => $admin->id,
            'type' => 'report_export',
            'status' => 'running',
            'progress' => 42,
            'payload' => ['source' => 'product_catalog'],
            'started_at' => now()->subMinutes(60),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/background-tasks/report-export', [
            'format' => 'csv',
            'source' => 'product_catalog',
            'filename' => 'products-test',
            'columns' => [
                ['key' => 'product_code', 'label' => 'Code'],
            ],
            'search_params' => ['page' => 1, 'per_page' => 25],
        ])->assertAccepted();

        $this->assertSame(
            1,
            BackgroundTask::query()
                ->where('user_id', $admin->id)
                ->where('status', 'failed')
                ->count(),
        );
    }

    public function test_active_running_task_still_blocks_new_export(): void
    {
        Queue::fake();

        $admin = User::where('username', 'admin')->firstOrFail();

        BackgroundTask::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => (int) $admin->organization_id,
            'user_id' => $admin->id,
            'type' => 'report_export',
            'status' => 'running',
            'progress' => 42,
            'payload' => ['source' => 'product_catalog'],
            'started_at' => now()->subMinutes(2),
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/v1/background-tasks/report-export', [
            'format' => 'csv',
            'source' => 'product_catalog',
            'filename' => 'products-test',
            'columns' => [
                ['key' => 'product_code', 'label' => 'Code'],
            ],
            'search_params' => ['page' => 1, 'per_page' => 25],
        ])->assertStatus(409);
    }

    public function test_product_catalog_export_job_completes(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $task = BackgroundTask::createPending(
            'report_export',
            (int) $admin->organization_id,
            (int) $admin->id,
            [
                'format' => 'csv',
                'filename' => 'products-test',
                'source' => 'product_catalog',
                'search_params' => ['page' => 1, 'per_page' => 25],
                'columns' => [
                    ['key' => 'product_code', 'label' => 'Code'],
                    ['key' => 'product_name', 'label' => 'Name'],
                ],
                'meta' => [],
            ],
        );

        $job = new GenerateReportExportJob($task->id);
        $job->handle(
            app(BackgroundTaskService::class),
            app(\App\Services\Background\InternalApiPaginator::class),
            app(\App\Services\Background\ProductCatalogExportFetcher::class),
            app(\App\Services\Background\ProductCatalogExportMapper::class),
            app(\App\Services\Background\ReportExportService::class),
        );

        $task->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertNotEmpty($task->result['disk_path'] ?? null);
    }
}
