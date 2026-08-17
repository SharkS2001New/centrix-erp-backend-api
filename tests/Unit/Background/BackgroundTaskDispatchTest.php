<?php

namespace Tests\Unit\Background;

use App\Jobs\GenerateReportExportJob;
use App\Jobs\ImportProductsJob;
use App\Jobs\ReportRunJob;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class BackgroundTaskDispatchTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_background_task_jobs_are_not_unique_locked(): void
    {
        $this->assertNotInstanceOf(ShouldBeUnique::class, new GenerateReportExportJob('x'));
        $this->assertNotInstanceOf(ShouldBeUnique::class, new ReportRunJob('x'));
        $this->assertNotInstanceOf(ShouldBeUnique::class, new ImportProductsJob('x'));
    }

    public function test_mark_running_claims_pending_task_only_once(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $task = BackgroundTask::query()->create([
            'id' => (string) Str::uuid(),
            'organization_id' => (int) $admin->organization_id,
            'user_id' => $admin->id,
            'type' => 'report_export',
            'status' => 'pending',
            'progress' => 0,
            'payload' => [],
        ]);

        $service = app(BackgroundTaskService::class);

        $this->assertTrue($service->markRunning($task));
        $this->assertSame('running', $task->fresh()->status);

        $this->assertFalse($service->markRunning($task));
        $this->assertSame('running', $task->fresh()->status);
    }
}
