<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Measures request handling time and exposes it for client-side latency split
 * (network vs API) via X-Response-Time and Server-Timing.
 */
class RecordResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $elapsedMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));

        $response->headers->set('X-Response-Time', $elapsedMs.'ms');
        $response->headers->set('Server-Timing', 'app;desc="Centrix API";dur='.$elapsedMs);

        return $response;
    }
}
