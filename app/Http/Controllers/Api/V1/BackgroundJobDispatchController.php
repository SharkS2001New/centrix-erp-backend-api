<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExportJob;
use App\Jobs\ReportBuilderPreviewJob;
use App\Jobs\ReportRunJob;
use App\Models\BackgroundTask;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use Illuminate\Http\Request;

class BackgroundJobDispatchController extends Controller
{
    public function __construct(
        protected BackgroundTaskService $tasks,
        protected InternalApiPaginator $paginator,
    ) {}

    /** POST /background-tasks/report-export */
    public function storeReportExport(Request $request)
    {
        $data = $request->validate([
            'format' => ['required', 'string', 'in:xlsx,csv,pdf,print'],
            'filename' => ['sometimes', 'string', 'max:120'],
            'source' => ['sometimes', 'string', 'in:api,inline_rows,legacy_archive_sales,product_catalog'],
            'path' => ['nullable', 'string', 'max:200'],
            'search_params' => ['sometimes', 'array'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.key' => ['required', 'string', 'max:120'],
            'columns.*.label' => ['required', 'string', 'max:200'],
            'columns.*.align' => ['nullable', 'string', 'max:20'],
            'meta' => ['sometimes', 'array'],
            'footer_row' => ['nullable', 'array'],
            'rows' => ['sometimes', 'array', 'max:5000'],
            'legacy_merge' => ['sometimes', 'array'],
            'legacy_merge.enabled' => ['sometimes', 'boolean'],
        ]);

        $source = $data['source'] ?? 'api';
        if ($source === 'api') {
            $path = (string) ($data['path'] ?? '');
            abort_if($path === '', 422, 'API path is required for report export.');
            $this->paginator->assertAllowedPath($path);
        } elseif ($source === 'inline_rows') {
            abort_if(empty($data['rows']), 422, 'Rows are required for inline export.');
        }

        $user = $request->user();
        $hasActiveTask = BackgroundTask::query()
            ->where('user_id', $user->id)
            ->where('organization_id', (int) $user->organization_id)
            ->whereIn('status', ['pending', 'running'])
            ->exists();

        abort_if($hasActiveTask, 409, 'Another background task is already running. Wait for it to finish.');

        $task = $this->tasks->create('report_export', $user, $data);
        GenerateReportExportJob::dispatch($task->id);

        return response()->json([
            'message' => 'Report export queued.',
            'task_id' => $task->id,
        ], 202);
    }

    /** POST /background-tasks/report-run */
    public function storeReportRun(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:200'],
            'search_params' => ['sometimes', 'array'],
        ]);

        $this->paginator->assertAllowedPath($data['path']);

        $task = $this->tasks->create('report_run', $request->user(), [
            'path' => $data['path'],
            'search_params' => $data['search_params'] ?? [],
        ]);

        ReportRunJob::dispatch($task->id);

        return response()->json([
            'message' => 'Report load queued.',
            'task_id' => $task->id,
        ], 202);
    }

    /** POST /background-tasks/report-builder-preview */
    public function storeReportBuilderPreview(Request $request)
    {
        $data = $request->validate([
            'spec' => ['required', 'array'],
            'filters' => ['sometimes', 'array'],
            'workspace_id' => ['nullable', 'string', 'max:50'],
        ]);

        $task = $this->tasks->create('report_builder_preview', $request->user(), [
            'spec' => $data['spec'],
            'filters' => $data['filters'] ?? [],
            'workspace_id' => $data['workspace_id'] ?? null,
        ]);

        ReportBuilderPreviewJob::dispatch($task->id);

        return response()->json([
            'message' => 'Report preview queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
