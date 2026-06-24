<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\PaginatedFetchJob;
use App\Services\Background\BackgroundTaskService;
use App\Services\Background\InternalApiPaginator;
use Illuminate\Http\Request;

class PaginatedFetchController extends Controller
{
    public function __construct(
        protected BackgroundTaskService $tasks,
        protected InternalApiPaginator $paginator,
    ) {}

    /** POST /background-tasks/paginated-fetch */
    public function store(Request $request)
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:200'],
            'search_params' => ['sometimes', 'array'],
        ]);

        $this->paginator->assertAllowedPath($data['path']);

        $task = $this->tasks->create('paginated_fetch', $request->user(), [
            'path' => $data['path'],
            'search_params' => $data['search_params'] ?? [],
        ]);

        PaginatedFetchJob::dispatch($task->id);

        return response()->json([
            'message' => 'Data fetch queued.',
            'task_id' => $task->id,
        ], 202);
    }
}
