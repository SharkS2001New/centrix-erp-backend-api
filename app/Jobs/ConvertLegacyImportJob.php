<?php

namespace App\Jobs;

use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Background\BackgroundTaskService;
use App\Services\Legacy\LightStoresCentrixImportCsvGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConvertLegacyImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public function __construct(
        public string $taskId,
    ) {}

    public function handle(BackgroundTaskService $tasks): void
    {
        $task = BackgroundTask::query()->find($this->taskId);
        if ($task === null || ! $tasks->markRunning($task)) {
            return;
        }

        try {
            $payload = is_array($task->payload) ? $task->payload : [];
            $storedPaths = $payload['stored_paths'] ?? [];
            if ($storedPaths === []) {
                throw new \RuntimeException('No uploaded SQL files found for conversion.');
            }

            $targetIndustry = LightStoresCentrixImportCsvGenerator::normalizeTargetIndustry(
                $payload['target_industry'] ?? null,
            );

            $files = [];
            $originalNames = $payload['original_names'] ?? [];
            foreach ($storedPaths as $index => $path) {
                $full = Storage::disk('local')->path($path);
                $originalName = is_string($originalNames[$index] ?? null) && $originalNames[$index] !== ''
                    ? $originalNames[$index]
                    : basename($path);
                $files[] = new UploadedFile(
                    $full,
                    $originalName,
                    'application/sql',
                    null,
                    true,
                );
            }

            $generator = LightStoresCentrixImportCsvGenerator::fromUploadedFiles($files, $targetIndustry);
            $zipPath = $generator->zipToTempFile();
            $filename = $targetIndustry === LightStoresCentrixImportCsvGenerator::TARGET_HOSPITALITY
                ? 'centrix-hotel-menu-import-csv.zip'
                : 'centrix-import-csv.zip';
            $target = 'legacy-imports/'.$task->id.'/'.$filename;
            Storage::disk('local')->put($target, file_get_contents($zipPath));
            @unlink($zipPath);

            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            $tasks->markCompleted($task, [
                'disk_path' => $target,
                'download_path' => $target,
                'filename' => $filename,
                'mime_type' => 'application/zip',
                'target_industry' => $targetIndustry,
            ]);
        } catch (\Throwable $e) {
            Log::warning('ConvertLegacyImportJob failed', [
                'task_id' => $this->taskId,
                'error' => $e->getMessage(),
            ]);
            $tasks->markFailed($task, $e->getMessage());
            throw $e;
        }
    }
}
