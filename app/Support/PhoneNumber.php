<?php

namespace App\Support;

class PhoneNumber
{
    /** Normalize to local Kenyan-style digits (e.g. 0712345678) for matching. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', trim($value)) ?? '';
        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '254') && strlen($digits) >= 12) {
            $digits = '0'.substr($digits, 3);
        }

        return $digits;
    }

    /** E.164 without plus — e.g. 254712345678 for WhatsApp Cloud API. */
    public static function toE164(?string $value, string $defaultCountryCode = '254'): ?string
    {
        $normalized = self::normalize($value);
        if ($normalized === null) {
            return null;
        }

        if (str_starts_with($normalized, '0')) {
            return $defaultCountryCode.substr($normalized, 1);
        }

        if (str_starts_with($normalized, $defaultCountryCode)) {
            return $normalized;
        }

        return $defaultCountryCode.ltrim($normalized, '0');
    }
}
