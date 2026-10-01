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
            $mapped = self::mappedMessageForCode($code, $technical);
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

    /**
     * Resolve a user-facing message for a device error code.
     * Comstore PLU upload uses E034; KRA TIS uses 34 for invalid receiptNo —
     * both collide when the device returns "(Code 34)".
     */
    public static function mappedMessageForCode(?string $code, string $technical = ''): ?string
    {
        if ($code === null || $code === '') {
            return null;
        }

        $unpadded = ltrim($code, '0');
        if ($unpadded === '') {
            $unpadded = '0';
        }
        $padded = strlen($code) < 3 ? str_pad($code, 3, '0', STR_PAD_LEFT) : $code;

        // Ambiguous 34 ↔ 034: pick by PLU vs receipt context.
        if (in_array($code, ['34', '034'], true) || ($unpadded === '34' && $padded === '034')) {
            if (self::looksLikePluUploadFailure($technical)) {
                $plu = config('kra_device_errors.codes.034');
                if (is_string($plu) && $plu !== '') {
                    return $plu;
                }
            }

            $receipt = config('kra_device_errors.codes.34');
            if (is_string($receipt) && $receipt !== '') {
                return $receipt;
            }
        }

        foreach (array_values(array_unique([$code, $padded, $unpadded])) as $candidate) {
            $mapped = config("kra_device_errors.codes.{$candidate}");
            if (is_string($mapped) && $mapped !== '') {
                return $mapped;
            }
        }

        return null;
    }

    /** True when the raw device fault is about product / PLU registration, not a sale receipt. */
    public static function looksLikePluUploadFailure(string $technical): bool
    {
        return (bool) preg_match(
            '/\bPLU\b|SetPluData|upload-plu|register-plu|plu_items|plu_data|Error processing PLU|product upload|UploadPlu|register products/i',
            $technical,
        );
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
