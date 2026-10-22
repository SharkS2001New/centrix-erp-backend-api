<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Platform\PlatformSpeedService;
use App\Services\Platform\Speed\PlatformSpeedSyntheticRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PlatformSpeedController extends Controller
{
    public function show(Request $request, PlatformSpeedService $speed)
    {
        $range = $this->range($request);

        return response()->json($speed->snapshot($range));
    }

    public function api(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->apiTab($this->range($request)));
    }

    public function database(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->databaseTab($this->range($request)));
    }

    public function infrastructure(PlatformSpeedService $speed)
    {
        return response()->json($speed->infrastructureTab());
    }

    public function queues(PlatformSpeedService $speed)
    {
        return response()->json($speed->queuesTab());
    }

    public function frontend(PlatformSpeedService $speed)
    {
        return response()->json($speed->frontendTab());
    }

    public function synthetic(PlatformSpeedService $speed)
    {
        return response()->json($speed->syntheticTab());
    }

    public function diagnostics(Request $request, PlatformSpeedService $speed)
    {
        return response()->json($speed->diagnosticsTab($this->range($request)));
    }

    public function storeSyntheticCheck(Request $request, PlatformSpeedSyntheticRunner $runner)
    {
        if (! Schema::hasTable('platform_speed_synthetic_checks')) {
            return response()->json(['message' => 'Synthetic tables missing. Run migrations.'], 503);
        }
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'method' => ['sometimes', Rule::in(['GET', 'HEAD'])],
            'path' => ['required', 'string', 'max:400', function ($attr, $value, $fail) {
                $v = (string) $value;
                if (preg_match('#/(sales|paybill|mpesa|stock|orders).*?(post|create|delete)#i', $v)) {
                    $fail('Synthetic checks must be safe read-only paths.');
                }
            }],
            'interval_seconds' => 'sometimes|integer|min:30|max:3600',
            'timeout_ms' => 'sometimes|integer|min:500|max:30000',
            'expect_status' => 'sometimes|integer|min:100|max:599',
            'auth_mode' => ['sometimes', Rule::in(['none', 'token'])],
            'is_active' => 'sometimes|boolean',
        ]);

        $id = DB::table('platform_speed_synthetic_checks')->insertGetId([
            'name' => $data['name'],
            'method' => $data['method'] ?? 'GET',
            'path' => $data['path'],
            'interval_seconds' => $data['interval_seconds'] ?? 60,
            'timeout_ms' => $data['timeout_ms'] ?? 5000,
            'expect_status' => $data['expect_status'] ?? 200,
            'auth_mode' => $data['auth_mode'] ?? 'none',
            'is_active' => $data['is_active'] ?? true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runner->ensureDefaults();

        return response()->json([
            'data' => (array) DB::table('platform_speed_synthetic_checks')->find($id),
        ], 201);
    }

    public function runSynthetic(PlatformSpeedSyntheticRunner $runner)
    {
        $result = $runner->runDue();

        return response()->json([
            'message' => 'Synthetic checks executed.',
            'ran' => $result['ran'],
            'failed' => $result['failed'],
        ]);
    }

    protected function range(Request $request): string
    {
        $range = (string) $request->query('range', '1h');

        return in_array($range, ['1h', '6h', '24h', '7d'], true) ? $range : '1h';
    }
}
