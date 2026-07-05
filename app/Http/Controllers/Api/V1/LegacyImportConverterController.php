<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ConvertLegacyImportJob;
use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use App\Services\Legacy\LightStoresCentrixImportCsvGenerator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class LegacyImportConverterController extends Controller
{
    public function __construct(
        protected BackgroundTaskService $tasks,
    ) {}

    /** POST /admin/legacy-import-converter/convert */
    public function convert(Request $request): BinaryFileResponse|Response|\Illuminate\Http\JsonResponse
    {
        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200'],
            'sync' => ['sometimes', 'boolean'],
        ]);

        $files = $request->file('files', []);
        if ($files === []) {
            return response([
                'message' => 'Upload at least one LightStores SQL dump file.',
            ], 422);
        }

        $totalBytes = array_sum(array_map(fn ($file) => (int) $file->getSize(), $files));
        $queue = ! $request->boolean('sync') || $totalBytes > 2_000_000 || count($files) > 2;

        if ($queue) {
            $storedPaths = [];
            foreach ($files as $index => $file) {
                $storedPaths[] = $file->storeAs(
                    'legacy-imports/uploads',
                    uniqid('sql_'.$index.'_', true).'.sql',
                    'local',
                );
            }

            $task = $this->tasks->create('legacy_import_convert', $request->user(), [
                'stored_paths' => $storedPaths,
            ]);
            ConvertLegacyImportJob::dispatch($task->id);

            return response()->json([
                'message' => 'Legacy SQL conversion queued.',
                'task_id' => $task->id,
                'queued' => true,
            ], 202);
        }

        try {
            $generator = LightStoresCentrixImportCsvGenerator::fromUploadedFiles($files);
            $zipPath = $generator->zipToTempFile();
        } catch (\Throwable $e) {
            return response([
                'message' => 'Could not convert SQL dumps: '.$e->getMessage(),
            ], 422);
        }

        return response()->download(
            $zipPath,
            'centrix-import-csv.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    /** GET /admin/legacy-import-converter/tasks/{taskId}/download */
    public function download(Request $request, string $taskId): BinaryFileResponse|Response
    {
        $task = BackgroundTask::query()->findOrFail($taskId);
        abort_unless($task->user_id === $request->user()?->id, 403);

        $path = $task->result['download_path'] ?? null;
        if (! is_string($path) || ! Storage::disk('local')->exists($path)) {
            return response(['message' => 'Converted file is not available.'], 404);
        }

        return response()->download(
            Storage::disk('local')->path($path),
            $task->result['filename'] ?? 'centrix-import-csv.zip',
            ['Content-Type' => 'application/zip'],
        );
    }
}
