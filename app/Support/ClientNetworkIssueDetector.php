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
    ];

    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function isClientNetworkIssue(string $message, ?array $context = null): bool
    {
        $candidates = [trim($message)];

        if (is_array($context)) {
            foreach (['user_message', 'message'] as $key) {
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
            foreach (self::MESSAGE_PATTERNS as $pattern) {
                if (str_contains($normalized, $pattern)) {
                    return true;
                }
            }
        }

        return false;
    }
}
