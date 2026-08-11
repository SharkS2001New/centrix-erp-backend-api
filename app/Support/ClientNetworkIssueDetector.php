<?php

namespace App\Support;

/**
 * Detect client-side connectivity failures that should not be logged as server errors.
 */
class ClientNetworkIssueDetector
{
    /** @var list<string> */
    protected const MESSAGE_PATTERNS = [
        'request timed out',
        'check your connection',
        'connection timed out',
        'connection refused',
        'network error',
        'network request failed',
        'failed host lookup',
        'no internet',
        'socketexception',
        'unable to resolve host',
        'failed to connect',
        'the internet connection appears to be offline',
        'likely user network',
        'slow connection',
        'failed to fetch',
        'networkerror',
        'load failed',
    ];

    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function isClientNetworkIssue(string $message, ?array $context = null): bool
    {
        $candidates = [trim($message)];

        if (is_array($context)) {
            foreach (['user_message', 'message', 'connectivity'] as $key) {
                if (! empty($context[$key]) && is_string($context[$key])) {
                    $candidates[] = trim($context[$key]);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $normalized = strtolower($candidate);
            if ($normalized === 'outage' || $normalized === 'slow_ping') {
                return true;
            }
            foreach (self::MESSAGE_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Slow reports must prove API/server work was slow — not the user's network RTT.
     *
     * @param  array<string, mixed>|null  $context
     */
    public static function shouldSkipSlowReport(
        ?string $apiPath,
        string $message,
        ?array $context,
        ?int $durationMs,
    ): bool {
        $path = strtolower((string) $apiPath);
        $pathOnly = explode('?', $path, 2)[0];

        if (
            str_contains($path, '/health')
            || str_contains($path, 'notifications/unread')
            || $pathOnly === '/notifications'
            || str_ends_with($pathOnly, '/notifications')
            || str_contains($pathOnly, '/notifications/')
        ) {
            return true;
        }

        $context = is_array($context) ? $context : [];
        $likely = strtolower((string) ($context['likely'] ?? ''));
        $connectivity = strtolower((string) ($context['connectivity'] ?? ''));

        if ($connectivity === 'slow_ping' || $connectivity === 'outage') {
            return true;
        }

        if ($likely === 'network' || $likely === 'unknown') {
            return true;
        }

        if (self::isClientNetworkIssue($message, $context)) {
            return true;
        }

        $serverMs = $context['server_ms'] ?? null;
        if ($serverMs === null || $serverMs === '') {
            // Without server timing we cannot prove system slowness.
            return true;
        }

        $serverMs = (int) $serverMs;
        if ($serverMs < 5000) {
            return true;
        }

        if ($durationMs !== null && $durationMs > 0 && $serverMs < (int) ($durationMs * 0.4)) {
            return true;
        }

        return false;
    }
}
