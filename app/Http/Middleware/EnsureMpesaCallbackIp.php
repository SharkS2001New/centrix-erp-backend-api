<?php

namespace App\Http\Middleware;

use App\Support\ClientIpResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EnsureMpesaCallbackIp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.mpesa_callback_ip_check', true)) {
            return $next($request);
        }

        $clientIp = ClientIpResolver::fromRequest($request);
        $allowed = ClientIpResolver::matchesAllowlist(
            $clientIp,
            config('security.mpesa_callback_cidrs', []),
            config('security.mpesa_callback_ips', []),
        );

        if ($allowed) {
            return $next($request);
        }

        Log::warning('M-Pesa callback rejected — IP not allowlisted', [
            'ip' => $clientIp,
            'path' => $request->path(),
        ]);

        return response()->json(['message' => 'Forbidden.'], 403);
    }
}
