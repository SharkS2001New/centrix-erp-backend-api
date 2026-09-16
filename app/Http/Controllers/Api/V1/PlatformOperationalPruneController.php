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
        $data = $request->validate([
            'dry_run' => 'sometimes|boolean',
            'optimize_tables' => 'sometimes|boolean',
        ]);

        $dryRun = (bool) ($data['dry_run'] ?? false);
        $optimize = (bool) ($data['optimize_tables'] ?? false) && ! $dryRun;

        if (function_exists('set_time_limit')) {
            @set_time_limit($optimize ? 600 : 300);
        }

        $results = $pruner->pruneAll($dryRun);
        $optimized = $optimize ? $pruner->optimizeRetentionTables() : [];

        return response()->json([
            'dry_run' => $dryRun,
            'deleted' => $results,
            'total' => array_sum($results),
            'optimized_tables' => $optimized,
            'status' => $pruner->platformStatus(),
        ]);
    }

    public function optimize(Request $request, OperationalDataPruneService $pruner)
    {
        $data = $request->validate([
            'tables' => 'sometimes|array|min:1',
            'tables.*' => 'string|max:100',
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(600);
        }

        $tables = $data['tables'] ?? null;
        $optimized = $pruner->optimizeRetentionTables($tables);

        if ($optimized === []) {
            throw ValidationException::withMessages([
                'tables' => 'No allowed retention tables to optimize.',
            ]);
        }

        return response()->json([
            'optimized_tables' => $optimized,
            'status' => $pruner->platformStatus(),
        ]);
    }
}
