<?php

namespace App\Services\Kra;

/**
 * Turn raw Comstore / KRA device errors into clear messages for cashiers and sales reps.
 */
final class KraDeviceErrorTranslator
{
    /** @return array{message: string, code: ?string, technical_message: string} */
    public static function translate(mixed $raw): array
    {
        $technical = self::normalizeTechnicalMessage($raw);
        $code = self::extractCode($technical);

        if ($code !== null) {
            $mapped = config("kra_device_errors.codes.{$code}");
            if (is_string($mapped) && $mapped !== '') {
                return [
                    'message' => $mapped,
                    'code' => $code,
                    'technical_message' => $technical,
                ];
            }
        }

        foreach (config('kra_device_errors.patterns', []) as $pattern => $message) {
            if (! is_string($pattern) || ! is_string($message)) {
                continue;
            }

            if (@preg_match($pattern, $technical) === 1) {
                return [
                    'message' => $message,
                    'code' => $code,
                    'technical_message' => $technical,
                ];
            }
        }

        return [
            'message' => self::fallbackMessage($technical),
            'code' => $code,
            'technical_message' => $technical,
        ];
    }

    public static function userMessage(mixed $raw): string
    {
        return self::translate($raw)['message'];
    }

    public static function normalizeTechnicalMessage(mixed $raw): string
    {
        $text = trim(is_string($raw) ? $raw : (json_encode($raw, JSON_UNESCAPED_UNICODE) ?: ''));

        if ($text === '') {
            return '';
        }

        if (preg_match('/HTTP request returned status code \d+:\s*(\{.*\})/s', $text, $matches) === 1) {
            $json = json_decode($matches[1], true);
            if (is_array($json)) {
                foreach (['message', 'Message', 'error', 'Error'] as $key) {
                    if (! empty($json[$key]) && is_string($json[$key])) {
                        return trim($json[$key]);
                    }
                }
            }
        }

        if (str_starts_with($text, 'Exception: ')) {
            $text = substr($text, strlen('Exception: '));
        }

        if (preg_match('/^HTTP request returned status code \d+:\s*(.+)$/s', $text, $matches) === 1) {
            $text = trim($matches[1]);
        }

        return trim($text);
    }

    public static function extractCode(string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/\(Code\s+(\d+)\)/i', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bCode\s+(\d+)\b/i', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\bE(\d{3})\b/i', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/ErrorCode["\']?\s*[:=]\s*["\']?(\d+)/i', $text, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    protected static function fallbackMessage(string $technical): string
    {
        $fallback = (string) config('kra_device_errors.fallback', 'KRA device rejected the request.');

        if ($technical === '' || self::looksLikeRawHttpNoise($technical)) {
            return $fallback;
        }

        if (strlen($technical) <= 180 && ! self::looksLikeRawHttpNoise($technical)) {
            return $technical;
        }

        return $fallback;
    }

    protected static function looksLikeRawHttpNoise(string $text): bool
    {
        return str_contains($text, 'HTTP request returned status code')
            || str_contains($text, 'cURL error')
            || (str_starts_with($text, '{') && str_contains($text, '"ModelState"'));
    }
}
