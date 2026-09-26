<?php

namespace Tests\Feature;

use App\Models\User;
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
    }

    public function test_non_super_admin_cannot_load_platform_speed(): void
    {
        Sanctum::actingAs(User::where('username', 'admin')->firstOrFail());

        $this->getJson('/api/v1/admin/platform-speed')->assertForbidden();
    }
}
