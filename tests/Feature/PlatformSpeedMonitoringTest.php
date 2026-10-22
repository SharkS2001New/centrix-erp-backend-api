<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Platform\Speed\PlatformSpeedHistogram;
use App\Services\Platform\Speed\PlatformSpeedMetricsFlusher;
use App\Services\Platform\Speed\PlatformSpeedMetricsRecorder;
use App\Services\Platform\Speed\PlatformSpeedRouteNormalizer;
use App\Services\Platform\Speed\PlatformSpeedSyntheticRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformSpeedMonitoringTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_route_normalizer_collapses_ids(): void
    {
        $normalizer = app(PlatformSpeedRouteNormalizer::class);
        $this->assertSame(
            'api/v1/products/{id}',
            $normalizer->normalizePath('api/v1/products/12345'),
        );
        $this->assertSame(
            'api/v1/customers/{id}/orders',
            $normalizer->normalizePath('api/v1/customers/9a0b1c2d-3e4f-5678-9abc-def012345678/orders'),
        );
    }

    public function test_histogram_percentiles(): void
    {
        $hist = app(PlatformSpeedHistogram::class);
        $buckets = $hist->emptyBuckets();
        for ($i = 0; $i < 100; $i++) {
            $hist->bucketIndex(40, $buckets);
        }
        for ($i = 0; $i < 5; $i++) {
            $hist->bucketIndex(900, $buckets);
        }
        $pct = $hist->percentiles($buckets, 105);
        $this->assertNotNull($pct['p50']);
        $this->assertGreaterThanOrEqual(40, $pct['p50']);
        $this->assertNotNull($pct['p95']);
        $this->assertGreaterThanOrEqual($pct['p50'], $pct['p95']);
    }

    public function test_api_metrics_flush_to_mysql(): void
    {
        config(['platform_speed.record_requests' => true, 'platform_speed.enabled' => true]);
        $recorder = app(PlatformSpeedMetricsRecorder::class);
        $recorder->recordApi('GET', 'api/v1/products', 120, 200, 512);
        $recorder->recordApi('GET', 'api/v1/products', 800, 200, 512);
        $flushed = app(PlatformSpeedMetricsFlusher::class)->flush();
        $this->assertGreaterThanOrEqual(1, $flushed['api']);

        $row = DB::table('platform_speed_api_minutes')
            ->where('route_key', 'api/v1/products')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->count);
        $this->assertGreaterThanOrEqual(800, (int) $row->max_ms);
    }

    public function test_health_path_excluded_from_recording(): void
    {
        $normalizer = app(PlatformSpeedRouteNormalizer::class);
        $request = Request::create('/api/v1/health', 'GET');
        $this->assertTrue($normalizer->shouldExclude($request));
    }

    public function test_synthetic_defaults_and_isolation(): void
    {
        $runner = app(PlatformSpeedSyntheticRunner::class);
        $runner->ensureDefaults();
        $this->assertGreaterThanOrEqual(2, DB::table('platform_speed_synthetic_checks')->count());

        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());
        $this->getJson('/api/v1/admin/platform-speed/synthetic')
            ->assertOk()
            ->assertJsonStructure(['checks', 'results', 'enabled']);
    }

    public function test_overview_includes_api_aggregates_shape(): void
    {
        Sanctum::actingAs(User::where('username', 'superadmin')->firstOrFail());
        $this->getJson('/api/v1/admin/platform-speed?range=1h')
            ->assertOk()
            ->assertJsonStructure([
                'api' => ['p50', 'p95', 'p99', 'throughput_per_min', 'slowest', 'trend'],
                'infrastructure' => ['cpu', 'memory', 'disk', 'queue'],
                'diagnostics',
                'monitoring',
            ]);
    }
}
