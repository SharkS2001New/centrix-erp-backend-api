<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaWhatsAppClient
{
    public function sendText(ResolvedWhatsAppConfig $config, string $toE164, string $body): bool
    {
        $message = trim($body);
        if ($message === '') {
            return true;
        }

        if ($config->accessToken === '' || ! $config->phoneNumberId) {
            Log::info('whatsapp.outbound', [
                'to' => $toE164,
                'body' => $message,
            ]);

            return true;
        }

        $version = $config->graphApiVersion;
        $url = "https://graph.facebook.com/{$version}/{$config->phoneNumberId}/messages";

        $response = Http::withToken($config->accessToken)
            ->acceptJson()
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $toE164,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $message,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('whatsapp.send_failed', [
                'to' => $toE164,
                'status' => $response->status(),
                'body' => $response->json() ?? $response->body(),
            ]);

            return false;
        }

        return true;
    }
}
