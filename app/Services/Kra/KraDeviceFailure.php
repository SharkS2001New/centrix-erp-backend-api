<?php

namespace App\Services\Kra;

use Illuminate\Validation\ValidationException;

/**
 * Abort API operations when the on-prem KRA device rejects a request.
 * Callers should invoke this inside a DB transaction so the whole operation rolls back.
 */
final class KraDeviceFailure
{
    public static function abort(string $message): never
    {
        throw ValidationException::withMessages([
            'kra' => $message !== '' ? $message : 'KRA device rejected the request.',
        ]);
    }

    /** @param  array<string, mixed>  $result */
    public static function abortUnlessSuccess(array $result, string $fallback = 'KRA device rejected the request.'): void
    {
        if ($result['success'] ?? false) {
            return;
        }

        self::abort((string) ($result['message'] ?? $fallback));
    }
}
