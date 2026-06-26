<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_reports_database_connectivity(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.app', true)
            ->assertJsonPath('checks.database', true);
    }

    public function test_connectivity_health_probe_skips_database(): void
    {
        $this->getJson('/api/v1/health?connectivity=1')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('checks.app', true)
            ->assertJsonMissingPath('checks.database');
    }
}
