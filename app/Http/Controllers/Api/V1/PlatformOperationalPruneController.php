<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Retention\OperationalDataPruneService;
use Illuminate\Http\Request;

class PlatformOperationalPruneController extends Controller
{
    public function show(OperationalDataPruneService $pruner)
    {
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
}
