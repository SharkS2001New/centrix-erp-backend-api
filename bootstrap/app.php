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
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $trusted = env('TRUSTED_PROXIES', '*');
        if ($trusted === '*' || $trusted === '') {
            $middleware->trustProxies(at: '*');
        } else {
            $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) $trusted)))));
        }

        // Token-based API auth (Bearer). Do not enable statefulApi() — the web/mobile
        // clients do not use Sanctum cookie sessions or CSRF cookies.
        $middleware->prependToGroup('api', \App\Http\Middleware\RecordResponseTime::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\SecurityHeaders::class);
        // Inactivity is handled by the web app lock screen — do not revoke API tokens mid-session.
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureUserIsActive::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureOrganizationLicenseActive::class);
        $middleware->prependToGroup('api', \App\Http\Middleware\EnsureLoginChannel::class);
        // Must run before EnsureUserIsActive / EnsureLoginChannel / auth:sanctum read Bearer from cookie.
        $middleware->prependToGroup('api', \App\Http\Middleware\AuthenticateApiTokenCookie::class);
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return null;
        });
        $middleware->alias([
            'erp.module' => \App\Http\Middleware\EnsureErpModule::class,
            'erp.module_any' => \App\Http\Middleware\EnsureAnyErpModule::class,
            'erp.report_module' => \App\Http\Middleware\EnsureReportModule::class,
            'erp.permission' => \App\Http\Middleware\EnsurePermission::class,
            'erp.mobile_sales' => \App\Http\Middleware\EnsureMobileSalesAppEnabled::class,
            'erp.manager_app' => \App\Http\Middleware\EnsureManagerAppEnabled::class,
            'erp.mobile_driver' => \App\Http\Middleware\EnsureMobileDriverAppEnabled::class,
            'erp.admin' => \App\Http\Middleware\EnsureAdmin::class,
            'erp.super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'erp.org_provisioning' => \App\Http\Middleware\EnsureOrgProvisioningAllowed::class,
            'erp.tenant' => \App\Http\Middleware\ResolveActingTenantUser::class,
            'erp.act_as_organization' => \App\Http\Middleware\ActAsOrganization::class,
            'erp.forbid_tenant_settings' => \App\Http\Middleware\ForbidTenantOrganizationSettings::class,
            'erp.session_idle' => \App\Http\Middleware\EnsureSessionNotIdle::class,
            'erp.password_expiry' => \App\Http\Middleware\EnsurePasswordNotForcedExpired::class,
            'erp.organization_license' => \App\Http\Middleware\EnsureOrganizationLicenseActive::class,
            'erp.mpesa_callback_ip' => \App\Http\Middleware\EnsureMpesaCallbackIp::class,
            'erp.legacy_archive' => \App\Http\Middleware\EnsureLegacyArchiveEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->renderable(function (\Illuminate\Database\Eloquent\ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            if ($e->getModel() === \App\Models\User::class) {
                return response()->json([
                    'message' => 'The requested user account was not found. Sign out and sign in again if this persists.',
                    'code' => 'user_not_found',
                ], 404);
            }

            return null;
        });
        $exceptions->renderable(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof AuthenticationException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            if (! str_contains($request->path(), 'import-batch')) {
                return null;
            }

            report($e);

            return response()->json([
                'message' => 'Import failed.',
                'detail' => $e->getMessage() !== '' ? $e->getMessage() : class_basename($e),
            ], 500);
        });
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof AuthenticationException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                return null;
            }

            if (! $request->is('api/v1/admin/database-backups*')) {
                return null;
            }

            report($e);

            $detail = $e instanceof \App\Services\Backup\DatabaseBackupException
                ? $e->getMessage()
                : (config('app.debug') || config('backup.expose_error_detail', true) ? $e->getMessage() : null);

            return response()->json(array_filter([
                'message' => 'Database backup failed.',
                'code' => $e instanceof \App\Services\Backup\DatabaseBackupException
                    ? $e->codeKey
                    : 'backup_failed',
                'detail' => $detail,
            ], fn ($value) => $value !== null && $value !== ''), 500);
        });
        $exceptions->renderable(function (\App\Exceptions\MissingProductWeightsException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json($e->toArray(), 422);
            }
        });
        $exceptions->renderable(function (\InvalidArgumentException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
        $exceptions->renderable(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') || ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof AuthenticationException
                || $e instanceof \Illuminate\Validation\ValidationException
                || $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $e instanceof \App\Exceptions\MissingProductWeightsException
                || $e instanceof \InvalidArgumentException) {
                return null;
            }

            report($e);

            $payload = \App\Support\ApiErrorPresenter::userMessage($e, $request, $request->user());
            $issueReport = app(\App\Services\SystemIssues\SystemIssueReporter::class)
                ->reportException($e, $request, $request->user());
            if ($issueReport) {
                $payload['issue_report_id'] = $issueReport->id;
            }

            return response()->json($payload, 500);
        });
    })->create();
