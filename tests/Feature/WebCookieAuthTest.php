<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RefreshesErpDatabase;
use Tests\TestCase;

class WebCookieAuthTest extends TestCase
{
    use RefreshesErpDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'security.api_token_cookie.enabled' => true,
            'security.api_token_cookie.name' => 'centrix_api_token',
            'security.api_token_cookie.secure' => false,
            'security.api_token_cookie.same_site' => 'none',
        ]);
    }

    public function test_backoffice_login_sets_http_only_cookie_and_omits_token_from_body(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_COOKIE_TEST',
            'login_channel' => 'backoffice',
        ]);

        $response->assertOk()
            ->assertJsonPath('token', null)
            ->assertJsonStructure(['user', 'organization']);

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNotEmpty($cookie->getValue());
    }

    public function test_mobile_login_keeps_bearer_token_in_body(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MOBILE_COOKIE_TEST',
            'login_channel' => 'mobile',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'organization']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_manager_login_keeps_bearer_token_in_body(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $response = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'MANAGER_COOKIE_TEST',
            'login_channel' => 'manager',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user', 'organization']);

        $this->assertNotEmpty($response->json('token'));

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');

        $this->assertNull($cookie);
    }

    public function test_api_accepts_auth_from_http_only_cookie(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_COOKIE_ME',
            'login_channel' => 'backoffice',
        ])->assertOk();

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');
        $this->assertNotNull($cookie);

        $this->withCookie('centrix_api_token', $cookie->getValue())
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('username', $user->username);
    }

    public function test_logout_clears_http_only_cookie(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_COOKIE_LOGOUT',
            'login_channel' => 'backoffice',
        ])->assertOk();

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');
        $this->assertNotNull($cookie);

        $logout = $this->withCookie('centrix_api_token', $cookie->getValue())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $cleared = collect($logout->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');

        $this->assertNotNull($cleared);
        $this->assertTrue($cleared->getExpiresTime() < time());
    }

    public function test_logout_revokes_token_from_cookie_without_sanctum_auth(): void
    {
        $user = User::query()->where('username', 'admin')->first();
        $this->assertNotNull($user);

        $login = $this->postJson('/api/v1/auth/login', [
            'company_code' => 'DEMO',
            'username' => $user->username,
            'password' => 'password',
            'client_id' => 'WEB_COOKIE_GUEST_LOGOUT',
            'login_channel' => 'backoffice',
        ])->assertOk();

        $cookie = collect($login->headers->getCookies())
            ->first(fn ($c) => $c->getName() === 'centrix_api_token');
        $this->assertNotNull($cookie);

        $this->withCookie('centrix_api_token', $cookie->getValue())
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $this->withCookie('centrix_api_token', $cookie->getValue())
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }
}
