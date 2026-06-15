<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureSessionNotIdle::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureUserIsActive::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureLoginChannel::class);
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return null;
        });
        $middleware->alias([
            'erp.module' => \App\Http\Middleware\EnsureErpModule::class,
            'erp.permission' => \App\Http\Middleware\EnsurePermission::class,
            'erp.admin' => \App\Http\Middleware\EnsureAdmin::class,
            'erp.super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'erp.org_provisioning' => \App\Http\Middleware\EnsureOrgProvisioningAllowed::class,
            'erp.tenant' => \App\Http\Middleware\ResolveActingTenantUser::class,
            'erp.session_idle' => \App\Http\Middleware\EnsureSessionNotIdle::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->renderable(function (\InvalidArgumentException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
