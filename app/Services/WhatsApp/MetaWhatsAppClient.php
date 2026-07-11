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

    /**
     * Download inbound WhatsApp media bytes via Graph API.
     *
     * @return array{bytes: string, mime_type: string}|null
     */
    public function downloadMedia(ResolvedWhatsAppConfig $config, string $mediaId): ?array
    {
        $mediaId = trim($mediaId);
        if ($mediaId === '' || $config->accessToken === '') {
            return null;
        }

        $version = $config->graphApiVersion;
        $meta = Http::withToken($config->accessToken)
            ->acceptJson()
            ->get("https://graph.facebook.com/{$version}/{$mediaId}");

        if (! $meta->successful()) {
            Log::warning('whatsapp.media_meta_failed', [
                'media_id' => $mediaId,
                'status' => $meta->status(),
                'body' => $meta->json() ?? $meta->body(),
            ]);

            return null;
        }

        $downloadUrl = (string) ($meta->json('url') ?? '');
        $mime = (string) ($meta->json('mime_type') ?? 'application/octet-stream');
        if ($downloadUrl === '') {
            return null;
        }

        $bin = Http::withToken($config->accessToken)
            ->withHeaders(['Accept' => '*/*'])
            ->get($downloadUrl);

        if (! $bin->successful()) {
            Log::warning('whatsapp.media_download_failed', [
                'media_id' => $mediaId,
                'status' => $bin->status(),
            ]);

            return null;
        }

        $bytes = $bin->body();
        if ($bytes === '') {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime_type' => $mime !== '' ? $mime : 'application/octet-stream',
        ];
    }
}
