<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * QZ Tray certificate + request signing for production silent printing.
 *
 * Set env:
 * - QZ_CERTIFICATE — PEM public certificate (or path via QZ_CERTIFICATE_PATH)
 * - QZ_PRIVATE_KEY — PEM private key (or path via QZ_PRIVATE_KEY_PATH)
 *
 * @see https://qz.io/docs/signing
 */
class QzTrayController extends Controller
{
    public function certificate(): JsonResponse
    {
        $cert = $this->loadPem(
            config('qz.certificate'),
            config('qz.certificate_path'),
            'QZ certificate',
        );

        if ($cert instanceof JsonResponse) {
            return $cert;
        }

        return response()->json(['certificate' => $cert]);
    }

    public function sign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to_sign' => 'required|string|max:100000',
        ]);

        $keyPem = $this->loadPem(
            config('qz.private_key'),
            config('qz.private_key_path'),
            'QZ private key',
        );

        if ($keyPem instanceof JsonResponse) {
            return $keyPem;
        }

        $key = openssl_pkey_get_private($keyPem);
        if ($key === false) {
            Log::error('QZ Tray private key could not be parsed.');

            return response()->json([
                'message' => 'QZ signing key is invalid.',
                'code' => 'qz_key_invalid',
            ], 500);
        }

        $signature = '';
        $ok = openssl_sign($data['to_sign'], $signature, $key, OPENSSL_ALGO_SHA512);
        if (! $ok) {
            return response()->json([
                'message' => 'QZ signature failed.',
                'code' => 'qz_sign_failed',
            ], 500);
        }

        return response()->json([
            'signature' => base64_encode($signature),
        ]);
    }

    /**
     * @return string|JsonResponse
     */
    protected function loadPem(mixed $inline, mixed $path, string $label): string|JsonResponse
    {
        $inline = is_string($inline) ? trim($inline) : '';
        if ($inline !== '') {
            return str_replace('\\n', "\n", $inline);
        }

        $path = is_string($path) ? trim($path) : '';
        if ($path !== '' && is_readable($path)) {
            $contents = file_get_contents($path);
            if (is_string($contents) && trim($contents) !== '') {
                return $contents;
            }
        }

        return response()->json([
            'message' => "{$label} is not configured. Set QZ_CERTIFICATE / QZ_PRIVATE_KEY (or *_PATH) for silent QZ Tray signing.",
            'code' => 'qz_not_configured',
        ], 503);
    }
}
