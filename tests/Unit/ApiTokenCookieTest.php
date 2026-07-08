<?php

namespace Tests\Unit;

use App\Services\Auth\ApiTokenCookie;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiTokenCookieTest extends TestCase
{
    public function test_mobile_and_manager_channels_use_bearer_token_in_body(): void
    {
        $this->assertTrue(ApiTokenCookie::channelUsesBearerTokenInBody('mobile'));
        $this->assertTrue(ApiTokenCookie::channelUsesBearerTokenInBody('manager'));
        $this->assertFalse(ApiTokenCookie::channelUsesBearerTokenInBody('backoffice'));
        $this->assertFalse(ApiTokenCookie::channelUsesBearerTokenInBody('pos'));
    }

    public function test_cookie_auth_disabled_when_native_app_channel_logs_in(): void
    {
        config(['security.api_token_cookie.enabled' => true]);

        foreach (['mobile', 'manager'] as $channel) {
            $request = Request::create('/api/v1/auth/login', 'POST', [
                'login_channel' => $channel,
            ]);

            $this->assertFalse(ApiTokenCookie::usesCookieAuth($request));
        }
    }

    public function test_cookie_auth_enabled_for_backoffice_when_configured(): void
    {
        config(['security.api_token_cookie.enabled' => true]);

        $request = Request::create('/api/v1/auth/login', 'POST', [
            'login_channel' => 'backoffice',
        ]);

        $this->assertTrue(ApiTokenCookie::usesCookieAuth($request));
    }
}
