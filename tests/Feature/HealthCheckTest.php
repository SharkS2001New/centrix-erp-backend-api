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
}
