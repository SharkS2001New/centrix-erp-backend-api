<?php

namespace App\Services\Auth;

class UsernameNormalizer
{
    public static function forLookup(string $username): string
    {
        return strtoupper(trim($username));
    }

    public static function forStorage(?string $username): ?string
    {
        if ($username === null) {
            return null;
        }

        $normalized = self::forLookup($username);

        return $normalized === '' ? null : $normalized;
    }
}
