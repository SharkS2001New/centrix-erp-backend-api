<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;

class WhatsAppWebhookSignatureValidator
{
    public function isConfigured(): bool
    {
        return $this->appSecret() !== '';
    }

    public function isValid(Request $request): bool
    {
        $secret = $this->appSecret();
        if ($secret === '') {
            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if ($header === '' || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    protected function appSecret(): string
    {
        return trim((string) config('whatsapp.app_secret', ''));
    }
}
