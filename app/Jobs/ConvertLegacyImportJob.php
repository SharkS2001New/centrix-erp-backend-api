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
        if ($task === null) {
            return;
        }

        $tasks->markRunning($task);

        try {
            $payload = is_array($task->payload) ? $task->payload : [];
            $storedPaths = $payload['stored_paths'] ?? [];
            if ($storedPaths === []) {
                throw new \RuntimeException('No uploaded SQL files found for conversion.');
            }

            $files = [];
            foreach ($storedPaths as $path) {
                $full = Storage::disk('local')->path($path);
                $files[] = new UploadedFile(
                    $full,
                    basename($path),
                    'application/sql',
                    null,
                    true,
                );
            }

            $generator = LightStoresCentrixImportCsvGenerator::fromUploadedFiles($files);
            $zipPath = $generator->zipToTempFile();
            $target = 'legacy-imports/'.$task->id.'/centrix-import-csv.zip';
            Storage::disk('local')->put($target, file_get_contents($zipPath));
            @unlink($zipPath);

            foreach ($storedPaths as $path) {
                Storage::disk('local')->delete($path);
            }

            $tasks->markCompleted($task, [
                'download_path' => $target,
                'filename' => 'centrix-import-csv.zip',
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
