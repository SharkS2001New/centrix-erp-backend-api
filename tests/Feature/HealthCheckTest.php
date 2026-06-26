<?php

namespace Tests\Feature;

use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshesErpDatabase;
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

    public function test_connectivity_health_allows_pos_session_token(): void
    {
        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'cashier',
            'password' => 'password',
            'client_id' => 'POS_HEALTH_PROBE',
            'login_channel' => 'pos',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/health?connectivity=1')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_connectivity_health_allows_pos_cookie_auth_session(): void
    {
        config([
            'security.api_token_cookie.enabled' => true,
            'security.api_token_cookie.name' => 'centrix_api_token',
        ]);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => 'cashier',
            'password' => 'password',
            'client_id' => 'POS_COOKIE_HEALTH',
            'login_channel' => 'pos',
        ])->assertOk();

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');
        $this->assertNotNull($cookie);

        $this->withUnencryptedCookie('centrix_api_token', $cookie->getValue())
            ->getJson('/api/v1/health?connectivity=1')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }
}
