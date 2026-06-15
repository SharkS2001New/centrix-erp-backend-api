<?php

namespace App\Http\Middleware;

use App\Services\Auth\UserLoginChannelService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;

class EnsureLoginChannel
{
    public function __construct(
        protected UserLoginChannelService $channels,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $plainTextToken = $request->bearerToken();
        if (! $plainTextToken) {
            return $next($request);
        }

        $accessToken = Sanctum::personalAccessTokenModel()::findToken($plainTextToken);
        if (! $accessToken) {
            return $next($request);
        }

        $loginChannel = (string) ($accessToken->login_channel ?? UserLoginChannelService::BACKOFFICE);
        if ($this->channels->tokenCanAccessPath($loginChannel, $request->path())) {
            return $next($request);
        }

        $accessToken->delete();

        return response()->json([
            'message' => sprintf(
                'This session was started from %s and cannot access this part of the system. Sign in with an allowed channel.',
                $this->channels->label($loginChannel),
            ),
            'code' => 'login_channel_forbidden',
        ], 403);
    }
}
