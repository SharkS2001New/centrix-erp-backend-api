<?php

namespace App\Services\SystemIssues;

class SystemIssueFingerprint
{
    public static function fromParts(string $kind, string $message, ?string $apiPath = null): string
    {
        $normalizedMessage = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        $normalizedMessage = mb_substr($normalizedMessage, 0, 200);

        $payload = implode('|', [
            strtolower(trim($kind)),
            strtolower(trim((string) $apiPath)),
            strtolower($normalizedMessage),
        ]);

        return hash('sha256', $payload);
    }

    public static function forReport(string $kind, string $message, ?string $apiPath = null): string
    {
        return self::fromParts($kind, $message, $apiPath);
    }
}
