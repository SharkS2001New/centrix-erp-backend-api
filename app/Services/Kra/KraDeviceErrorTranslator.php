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

    /**
     * Same as userMessage, but replace checkout soft-fail phrasing when the document
     * is a credit note / return (return may already be approved and queued).
     */
    public static function userMessageForDocument(mixed $raw, string $documentType = 'sale'): string
    {
        $message = self::userMessage($raw);
        if ($documentType !== 'credit_note' && $documentType !== 'return') {
            return $message;
        }

        $replacements = [
            'The sale was saved without a KRA QR.' => 'The return was approved; KRA credit will retry automatically while Centrix KRA Agent is online.',
            'The receipt was saved without a KRA QR.' => 'The return was approved; KRA credit will retry automatically while Centrix KRA Agent is online.',
            'then try the sale again. The receipt was saved without a KRA QR.' => 'then retry will run automatically. The return was already approved.',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $message);
    }

    /** Connectivity / agent-offline failures that should auto-retry as kra_status=pending. */
    public static function isTransientConnectivityFailure(mixed $raw): bool
    {
        $translated = self::translate($raw);
        $code = $translated['code'];
        if (in_array($code, ['90', '96', '518', '519', '520'], true)) {
            return true;
        }

        $haystack = trim($translated['technical_message'].' '.$translated['message']);

        return $haystack !== '' && (bool) preg_match(
            '/did not respond|not respond in time|Could not reach|Comstore is not|Comstore not reachable|has not checked in|has never checked in|timed out|Connection refused|cURL error|aborted without|not online|not available|unavailable|Start Comstore|fiscal unavailable|Device offline/i',
            $haystack,
        );
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

        // Agent style: "Comstore HTTP 500: Signature generation failed (Code 314)"
        // or "Comstore HTTP 500: {\"message\":\"...\"}"
        if (preg_match('/^Comstore HTTP \d+\s*:\s*(.+)$/is', $text, $matches) === 1) {
            $detail = trim($matches[1]);
            if (str_starts_with($detail, '{')) {
                $json = json_decode($detail, true);
                if (is_array($json)) {
                    foreach (['message', 'Message', 'error', 'Error', 'detail', 'Detail'] as $key) {
                        if (! empty($json[$key]) && is_string($json[$key])) {
                            return trim($json[$key]);
                        }
                    }
                }
            }
            if ($detail !== '') {
                return $detail;
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

        // Comstore middleware style: "519 error code, aborted without a reason"
        if (preg_match('/\b(\d{3})\s+error\s*code\b/i', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/\berror\s*code\s*[,:]?\s*(\d{3})\b/i', $text, $matches) === 1) {
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

        if ($technical === '' || self::looksLikeRawHttpNoise($technical) || self::looksLikeCrypticDeviceNoise($technical)) {
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
            || (bool) preg_match('/^Comstore HTTP \d+$/i', $text)
            || (str_starts_with($text, '{') && str_contains($text, '"ModelState"'));
    }

    /** Raw middleware strings that should never be shown to cashiers as-is. */
    protected static function looksLikeCrypticDeviceNoise(string $text): bool
    {
        // Keep clear Centrix / Comstore guidance intact.
        if (preg_match('/Comstore|Centrix KRA Agent|fiscal device|Smart VSCU|start Comstore/i', $text) === 1) {
            return false;
        }

        return (bool) preg_match(
            '/\berror\s*code\b|aborted without a reason|signal is aborted|operation was aborted/i',
            $text,
        );
    }
}
