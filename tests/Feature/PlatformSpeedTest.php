<?php

namespace Tests\Feature;

use App\Models\SystemIssueReport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class PlatformSpeedTest extends TestCase
{
    use RefreshesErpDatabase;

    public function test_super_admin_can_load_platform_speed_snapshot(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $response = $this->getJson('/api/v1/admin/platform-speed');

        $response->assertOk()
            ->assertJsonStructure([
                'checked_at',
                'status',
                'server_ms',
                'health_ms',
                'latency_probe' => [
                    'db_ms',
                    'redis_ms',
                    'redis_skipped',
                ],
                'health' => [
                    'ok',
                    'checked_at',
                    'hostname',
                    'checks',
                ],
                'slow_queries' => [
                    'available',
                    'queries',
                ],
                'slow_issues' => [
                    'open',
                    'acknowledged',
                    'active',
                    'recent',
                ],
            ]);

        $this->assertContains($response->json('status'), ['ok', 'degraded', 'critical']);
        $this->assertIsInt($response->json('server_ms'));
        $this->assertIsInt($response->json('health_ms'));

        // Probe time must be the cheap DB/Redis ping — not the 3s queue worker wait.
        $queueCheck = collect($response->json('health.checks') ?? [])
            ->firstWhere('id', 'queue');
        if (is_array($queueCheck) && config('queue.default') !== 'sync') {
            $this->assertStringContainsString('skipped', (string) ($queueCheck['detail'] ?? ''));
        }
        $this->assertLessThan(2000, (int) $response->json('server_ms'));
    }

    public function test_non_super_admin_cannot_load_platform_speed(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/admin/platform-speed')->assertForbidden();
    }

    public function test_api_tab_lists_active_slow_reports_grouped_by_route(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        foreach ([
            ['status' => 'open', 'duration_ms' => 6200],
            ['status' => 'acknowledged', 'duration_ms' => 5100],
            ['status' => 'resolved', 'duration_ms' => 9000],
        ] as $attributes) {
            SystemIssueReport::create([
                'organization_id' => null,
                'user_id' => $superAdmin->id,
                'kind' => 'slow',
                'status' => $attributes['status'],
                'message' => 'Slow sales order transition',
                'api_path' => '/api/v1/sales/orders/43953/transition',
                'http_method' => 'POST',
                'duration_ms' => $attributes['duration_ms'],
            ]);
        }

        $this->getJson('/api/v1/admin/platform-speed/api?range=1h')
            ->assertOk()
            ->assertJsonCount(1, 'slow_endpoints')
            ->assertJsonPath('slow_endpoints.0.method', 'POST')
            ->assertJsonPath('slow_endpoints.0.route_key', 'api/v1/sales/orders/{id}/transition')
            ->assertJsonPath('slow_endpoints.0.report_count', 2)
            ->assertJsonPath('slow_endpoints.0.reported_max_ms', 6200);
    }

    public function test_super_admin_can_read_failed_queue_job_exceptions(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        DB::table('failed_jobs')->insert([
            'uuid' => 'speed-test-failed-job',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\ExampleJob',
                'data' => ['commandName' => 'App\\Jobs\\ExampleJob'],
            ]),
            'exception' => "RuntimeException: Test queue failure\n#0 /app/Jobs/ExampleJob.php(20): run()",
            'failed_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/platform-speed/failed-jobs')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('old_count', 0)
            ->assertJsonPath('clear_after_days', 30)
            ->assertJsonPath('data.0.display_name', 'App\\Jobs\\ExampleJob')
            ->assertJsonPath('data.0.exception', "RuntimeException: Test queue failure\n#0 /app/Jobs/ExampleJob.php(20): run()")
            ->assertJsonPath('data.0.is_old', false);
    }

    public function test_non_super_admin_cannot_read_failed_queue_job_exceptions(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/admin/platform-speed/failed-jobs')->assertForbidden();
    }

    public function test_super_admin_can_clear_failed_queue_jobs(): void
    {
        $superAdmin = User::where('username', 'superadmin')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        DB::table('failed_jobs')->insert([
            [
                'uuid' => 'speed-test-failed-job-clear-1',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\ExampleJob',
                    'data' => ['commandName' => 'App\\Jobs\\ExampleJob'],
                ]),
                'exception' => 'RuntimeException: clear me',
                'failed_at' => now()->subDays(40),
            ],
            [
                'uuid' => 'speed-test-failed-job-clear-2',
                'connection' => 'redis',
                'queue' => 'default',
                'payload' => json_encode([
                    'displayName' => 'App\\Jobs\\RecentJob',
                    'data' => ['commandName' => 'App\\Jobs\\RecentJob'],
                ]),
                'exception' => 'RuntimeException: keep me when pruning old',
                'failed_at' => now(),
            ],
        ]);

        $this->getJson('/api/v1/admin/platform-speed/failed-jobs')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('old_count', 1);

        // UI "Clear failed" sends all=true so same-day failures can be wiped after a fix.
        $this->deleteJson('/api/v1/admin/platform-speed/failed-jobs', [
            'confirm' => true,
            'all' => true,
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 2)
            ->assertJsonPath('all', true);

        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'speed-test-failed-job-clear-1']);
        $this->assertDatabaseMissing('failed_jobs', ['uuid' => 'speed-test-failed-job-clear-2']);
    }

    public function test_non_super_admin_cannot_clear_failed_queue_jobs(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->deleteJson('/api/v1/admin/platform-speed/failed-jobs', [
            'confirm' => true,
        ])->assertForbidden();
    }
}
