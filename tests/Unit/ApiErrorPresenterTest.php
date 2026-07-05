<?php

namespace Tests\Unit;

use App\Support\ApiErrorPresenter;
use Illuminate\Http\Request;
use ParseError;
use Tests\TestCase;

class ApiErrorPresenterTest extends TestCase
{
    public function test_super_admin_receives_detailed_server_error(): void
    {
        $user = new \App\Models\User(['is_super_admin' => true]);
        $request = Request::create('/api/v1/erp/settings', 'PATCH');
        $request->setUserResolver(fn () => $user);

        $payload = ApiErrorPresenter::userMessage(
            new ParseError('syntax error, unexpected token "protected"', 503),
            $request,
            $user,
        );

        $this->assertTrue($payload['expose_detail']);
        $this->assertStringContainsString('syntax error', $payload['message']);
        $this->assertStringContainsString('syntax error', $payload['detail']);
        $this->assertSame('Settings', $payload['module']);
    }

    public function test_regular_user_receives_module_scoped_message(): void
    {
        $user = new \App\Models\User(['is_super_admin' => false]);
        $request = Request::create('/api/v1/organizations/1', 'PATCH');
        $request->setUserResolver(fn () => $user);

        $payload = ApiErrorPresenter::userMessage(
            new ParseError('syntax error, unexpected token "protected"', 503),
            $request,
            $user,
        );

        $this->assertFalse($payload['expose_detail']);
        $this->assertSame(
            'An error occurred in Platform. Please report this to your system administrator.',
            $payload['message'],
        );
        $this->assertArrayNotHasKey('detail', $payload);
    }
}
