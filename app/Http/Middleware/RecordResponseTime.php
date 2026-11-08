<?php

namespace App\Http\Middleware;

use App\Services\Platform\Speed\PlatformSpeedMetricsRecorder;
use App\Services\Platform\Speed\PlatformSpeedQueryTracker;
use App\Services\Platform\Speed\PlatformSpeedRouteNormalizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Measures request handling time (X-Response-Time / Server-Timing) and records
 * aggregated API metrics for ERP Speed (no bodies / secrets).
 */
class RecordResponseTime
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        $normalizer = app(PlatformSpeedRouteNormalizer::class);
        $tracker = app(PlatformSpeedQueryTracker::class);
        $track = ! $normalizer->shouldExclude($request);
        if ($track) {
            $tracker->enableForRequest();
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            $tracker->disable();
        }

        $elapsedMs = max(0, (int) round((hrtime(true) - $started) / 1_000_000));

        $response->headers->set('X-Response-Time', $elapsedMs.'ms');
        $response->headers->set('Server-Timing', 'app;desc="Centrix API";dur='.$elapsedMs);

        if ($track) {
            $bytes = 0;
            if ($response->headers->has('Content-Length')) {
                $bytes = max(0, (int) $response->headers->get('Content-Length'));
            }
            try {
                app(PlatformSpeedMetricsRecorder::class)->recordApi(
                    $request->getMethod(),
                    $normalizer->routeKey($request),
                    $elapsedMs,
                    $response->getStatusCode(),
                    $bytes,
                );
            } catch (\Throwable) {
                // Never break the response for metrics.
            }
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            app(PlatformSpeedMetricsRecorder::class)->flush();
        } catch (\Throwable) {
            // Never break the response for metrics.
        }
    }
}
