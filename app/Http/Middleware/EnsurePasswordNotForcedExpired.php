<?php

namespace App\Http\Middleware;

use App\Services\Auth\PasswordExpiryService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePasswordNotForcedExpired
{
    /** @var list<string> */
    protected array $allowedPatterns = [
        'api/v1/auth/logout',
        'api/v1/auth/change-password',
        'api/v1/auth/set-required-password',
        'api/v1/auth/me',
        'api/v1/auth/verify-password',
        'api/v1/auth/skip-password-expiry',
        'api/v1/erp/capabilities',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $status = app(PasswordExpiryService::class)->statusForUser($user);
        if (! ($status['forced'] ?? false)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        foreach ($this->allowedPatterns as $pattern) {
            if ($path === trim($pattern, '/')) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Your password has expired. Change your password to continue.',
            'code' => 'password_expired_forced',
            'password_expiry' => $status,
        ], 403);
    }
}
