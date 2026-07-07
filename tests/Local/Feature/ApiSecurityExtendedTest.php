<?php

/**
 * Local-only tests — excluded from CI / default `composer test`.
 * Run manually: composer test:local
 */

namespace Tests\Local\Feature;

use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ApiSecurityExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('auth-login');
        RateLimiter::clear('auth-org-preview');
    }

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

    public function test_root_blocks_unauthorized_public_access(): void
    {
        $this->getJson('/')
            ->assertForbidden()
            ->assertJsonPath('message', 'Unauthorized. Public access to this API is not permitted.')
            ->assertJsonStructure(['message', 'hint', 'application']);
    }
}
