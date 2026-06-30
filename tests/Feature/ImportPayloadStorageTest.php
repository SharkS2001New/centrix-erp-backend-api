<?php

namespace Tests\Feature;

use App\Jobs\ImportCustomersJob;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\ImportPayloadStorage;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class ImportPayloadStorageTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_large_import_rows_are_stored_on_disk_and_loaded_by_job(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $rows = [];
        for ($i = 1; $i <= 250; $i++) {
            $rows[] = [
                'customer_name' => "Bulk Customer {$i}",
                'customer_type' => 'debtor',
                'phone_number' => '0712'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
            ];
        }

        $payload = app(ImportPayloadStorage::class)->payloadForRows($rows, inlineLimit: 50);
        $this->assertArrayHasKey('rows_path', $payload);
        $this->assertArrayNotHasKey('rows', $payload);

        $task = BackgroundTask::createPending(
            'customer_import',
            (int) $admin->organization_id,
            (int) $admin->id,
            $payload,
        );

        $job = new ImportCustomersJob($task->id);
        $job->handle(
            app(BackgroundTaskService::class),
            app(\App\Services\Customers\CustomerUniquenessValidator::class),
            app(\App\Services\Auth\UserAccessService::class),
        );

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertSame(250, $task->result['created'] ?? null);
        $this->assertFalse(
            \Illuminate\Support\Facades\Storage::disk('local')->exists($payload['rows_path']),
        );
    }
}
