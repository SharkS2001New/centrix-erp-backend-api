<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Retention\DataRetentionSettingsResolver;
use App\Services\Retention\OperationalDataPruneService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformOperationalPruneController extends Controller
{
    public function show(OperationalDataPruneService $pruner)
    {
        return response()->json($pruner->platformStatus());
    }

    public function updateSettings(Request $request, OperationalDataPruneService $pruner)
    {
        $data = $request->validate([
            'hikvision_access_events_days' => 'sometimes|integer|min:1|max:90',
            'attendance_days' => 'sometimes|integer|min:30|max:365',
            'hikvision_agent_commands_completed_days' => 'sometimes|integer|min:1|max:90',
            'hikvision_agent_commands_failed_days' => 'sometimes|integer|min:1|max:90',
            'kra_agent_commands_completed_days' => 'sometimes|integer|min:1|max:90',
            'kra_agent_commands_failed_days' => 'sometimes|integer|min:1|max:90',
            'released_stock_reservations_days' => 'sometimes|integer|min:1|max:90',
            'audit_logs_days' => 'sometimes|integer|min:1|max:90',
            'cancelled_sales_days' => 'sometimes|integer|min:1|max:90',
            'expired_sales_days' => 'sometimes|integer|min:1|max:90',
            'prune_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
        ]);

        try {
            DataRetentionSettingsResolver::update($data);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages([
                'settings' => $e->getMessage(),
            ]);
        }

        return response()->json($pruner->platformStatus());
    }

    public function run(Request $request, OperationalDataPruneService $pruner)
    {
        $data = $this->validateRunRequest($request);

        $optimizeOnly = (bool) ($data['optimize_only'] ?? false);
        if ($optimizeOnly) {
            return $this->respondOptimized(
                $pruner,
                $data['tables'] ?? null,
            );
        }

        $dryRun = (bool) ($data['dry_run'] ?? false);
        $optimize = (bool) ($data['optimize_tables'] ?? false) && ! $dryRun;
        $days = array_key_exists('days', $data) && $data['days'] !== null
            ? (int) $data['days']
            : null;
        $maxRows = array_key_exists('max_rows', $data) && $data['max_rows'] !== null
            ? (int) $data['max_rows']
            : null;
        $targets = $data['targets'] ?? null;

        if (function_exists('set_time_limit')) {
            @set_time_limit($optimize ? 600 : 300);
        }

        try {
            $results = $pruner->pruneTargets($targets, $days, $dryRun, null, $maxRows);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'targets' => $e->getMessage(),
            ]);
        }

        $optimizeTables = $data['tables'] ?? $targets;
        $optimizeResults = $optimize ? $pruner->optimizeRetentionTables(
            is_array($optimizeTables) && $optimizeTables !== [] ? $optimizeTables : null,
        ) : [];

        return response()->json([
            'dry_run' => $dryRun,
            'days' => $days,
            'max_rows' => $maxRows,
            'targets' => $targets ?? OperationalDataPruneService::TARGETS,
            'deleted' => $results,
            'total' => array_sum($results),
            'optimized_tables' => array_column($optimizeResults, 'name'),
            'optimize_results' => $optimizeResults,
            'status' => $pruner->platformStatus(),
        ]);
    }

    /**
     * Stream step-by-step prune / optimize logs (SSE) for the Data retention UI.
     */
    public function runStream(Request $request, OperationalDataPruneService $pruner)
    {
        $data = $this->validateRunRequest($request);

        $optimizeOnly = (bool) ($data['optimize_only'] ?? false);
        $dryRun = (bool) ($data['dry_run'] ?? false);
        $optimize = (bool) ($data['optimize_tables'] ?? false) && ! $dryRun && ! $optimizeOnly;
        $days = array_key_exists('days', $data) && $data['days'] !== null
            ? (int) $data['days']
            : null;
        $maxRows = array_key_exists('max_rows', $data) && $data['max_rows'] !== null
            ? (int) $data['max_rows']
            : null;
        $targets = $data['targets'] ?? null;
        $optimizeTables = $data['tables'] ?? $targets;

        if (function_exists('set_time_limit')) {
            @set_time_limit($optimize || $optimizeOnly ? 900 : 600);
        }

        return response()->stream(function () use (
            $pruner,
            $optimizeOnly,
            $dryRun,
            $optimize,
            $days,
            $maxRows,
            $targets,
            $optimizeTables,
        ) {
            $send = static function (array $event): void {
                echo 'data: '.json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                @flush();
            };

            $onProgress = static function (array $payload) use ($send): void {
                $send($payload + ['event' => $payload['event'] ?? 'step']);
            };

            try {
                if ($optimizeOnly) {
                    $send([
                        'event' => 'status',
                        'message' => 'OPTIMIZE TABLE starting…',
                        'phase' => 'start',
                    ]);
                    $optimizeResults = $pruner->optimizeRetentionTables(
                        is_array($optimizeTables) && $optimizeTables !== [] ? $optimizeTables : null,
                        $onProgress,
                    );
                    $send([
                        'event' => 'done',
                        'dry_run' => false,
                        'deleted' => [],
                        'total' => 0,
                        'optimized_tables' => array_column($optimizeResults, 'name'),
                        'optimize_results' => $optimizeResults,
                        'status' => $pruner->platformStatus(),
                        'message' => 'Optimized '.count($optimizeResults).' table(s).',
                    ]);

                    return;
                }

                $results = $pruner->pruneTargets($targets, $days, $dryRun, $onProgress, $maxRows);

                $optimizeResults = [];
                if ($optimize) {
                    $send([
                        'event' => 'status',
                        'message' => 'OPTIMIZE TABLE starting…',
                        'phase' => 'start',
                    ]);
                    $optimizeResults = $pruner->optimizeRetentionTables(
                        is_array($optimizeTables) && $optimizeTables !== [] ? $optimizeTables : null,
                        $onProgress,
                    );
                }

                $send([
                    'event' => 'done',
                    'dry_run' => $dryRun,
                    'days' => $days,
                    'max_rows' => $maxRows,
                    'targets' => $targets ?? OperationalDataPruneService::TARGETS,
                    'deleted' => $results,
                    'total' => array_sum($results),
                    'optimized_tables' => array_column($optimizeResults, 'name'),
                    'optimize_results' => $optimizeResults,
                    'status' => $pruner->platformStatus(),
                    'message' => $dryRun
                        ? 'Dry run complete — no rows were deleted.'
                        : 'Operational data prune complete.',
                ]);
            } catch (\InvalidArgumentException $e) {
                $send([
                    'event' => 'error',
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                report($e);
                $send([
                    'event' => 'error',
                    'message' => 'Prune failed: '.$e->getMessage(),
                ]);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function optimize(Request $request, OperationalDataPruneService $pruner)
    {
        $data = $request->validate([
            'tables' => 'sometimes|array|min:1',
            'tables.*' => 'string|max:100',
        ]);

        return $this->respondOptimized($pruner, $data['tables'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRunRequest(Request $request): array
    {
        return $request->validate([
            'dry_run' => 'sometimes|boolean',
            'optimize_tables' => 'sometimes|boolean',
            'optimize_only' => 'sometimes|boolean',
            'tables' => 'sometimes|array|min:1',
            'tables.*' => 'string|max:100',
            'days' => 'sometimes|nullable|integer|min:1|max:365',
            'max_rows' => 'sometimes|nullable|integer|min:1|max:500000',
            'targets' => 'sometimes|array|min:1',
            'targets.*' => 'string|max:100',
        ]);
    }

    /**
     * @param  list<string>|null  $tables
     */
    private function respondOptimized(OperationalDataPruneService $pruner, ?array $tables)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $optimizeResults = $pruner->optimizeRetentionTables($tables);

        if ($optimizeResults === []) {
            throw ValidationException::withMessages([
                'tables' => 'No allowed retention tables to optimize.',
            ]);
        }

        return response()->json([
            'optimized_tables' => array_column($optimizeResults, 'name'),
            'optimize_results' => $optimizeResults,
            'status' => $pruner->platformStatus(),
        ]);
    }
}
