<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth-login');
        RateLimiter::clear('auth-org-preview');
    }

    public function test_health_response_includes_security_headers(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /*
     * Local-only — run via `composer test:local` (see ApiSecurityExtendedTest).
     *
    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'username' => 'admin',
                'password' => 'wrong-password',
                'client_id' => 'test-client',
            ]);
        }

        $this->postJson('/api/v1/auth/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
            'client_id' => 'test-client',
        ])->assertStatus(429);
    }
    */

    public function test_mpesa_callback_rejects_non_allowlisted_ip_when_enabled(): void
    {
        config(['security.mpesa_callback_ip_check' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50'])
            ->postJson('/api/v1/payments/c2b/confirmation', [
                'TransID' => 'SEC123',
                'TransAmount' => '10',
                'MSISDN' => '254712345678',
            ])
            ->assertForbidden();
    }

    /*
     * Local-only — run via `composer test:local` (see ApiSecurityExtendedTest).
     *
    public function test_root_blocks_unauthorized_public_access(): void
    {
        $this->getJson('/')
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Public access to this API is not permitted.')
            ->assertJsonStructure(['message', 'hint', 'application']);
    }
    */

    public function test_cors_allows_configured_origin(): void
    {
        $this->options('/api/v1/health', [], [
            'Origin' => 'http://localhost:3000',
            'Access-Control-Request-Method' => 'GET',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
    }

    public function test_login_does_not_require_csrf_from_frontend_origin(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'username' => 'nobody',
            'password' => 'wrong-password',
            'client_id' => 'test-client',
        ], [
            'Origin' => 'https://centrixerp.betsassured.com',
        ])
            ->assertStatus(422)
            ->assertJsonMissing(['message' => 'CSRF token mismatch.']);
    }

    public function test_cors_allows_frontend_url_origin(): void
    {
        config(['cors.allowed_origins' => ['https://centrixerp.betsassured.com']]);

        $this->options('/api/v1/health', [], [
            'Origin' => 'https://centrixerp.betsassured.com',
            'Access-Control-Request-Method' => 'GET',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://centrixerp.betsassured.com');
    }
}
