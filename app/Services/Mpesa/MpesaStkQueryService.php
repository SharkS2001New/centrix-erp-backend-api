<?php

namespace App\Services\Mpesa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MpesaStkQueryService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function query(array $config, string $checkoutRequestId): array
    {
        $config = MpesaSettingsResolver::normalize($config);
        $shortCode = trim((string) ($config['shortcode'] ?? ''));
        if ($config['consumer_key'] === '' || $config['consumer_secret'] === ''
            || $shortCode === '' || $config['passkey'] === '') {
            throw new RuntimeException('Daraja STK Query requires the saved consumer key, consumer secret, shortcode, and passkey.');
        }

        $baseUrl = ($config['env'] ?? 'sandbox') === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';

        try {
            $tokenResponse = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->get($baseUrl.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            $tokenBody = $tokenResponse->json();
            if (! $tokenResponse->successful() || ! is_array($tokenBody) || empty($tokenBody['access_token'])) {
                throw new RuntimeException($this->providerError($tokenBody, 'Daraja did not return an access token for STK Query.'));
            }

            $timestamp = now()->format('YmdHis');
            $queryResponse = Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->withToken((string) $tokenBody['access_token'])
                ->post($baseUrl.'/mpesa/stkpushquery/v1/query', [
                    'BusinessShortCode' => $shortCode,
                    'Password' => base64_encode($shortCode.$config['passkey'].$timestamp),
                    'Timestamp' => $timestamp,
                    'CheckoutRequestID' => $checkoutRequestId,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to Safaricom Daraja STK Query.', previous: $e);
        }

        $body = $queryResponse->json();
        if (! $queryResponse->successful() || ! is_array($body)) {
            throw new RuntimeException($this->providerError($body, 'Daraja could not return the STK payment status.'));
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    private function providerError(?array $body, string $fallback): string
    {
        foreach (['errorMessage', 'ResponseDescription', 'message'] as $key) {
            $message = trim((string) ($body[$key] ?? ''));
            if ($message !== '') {
                return $message;
            }
        }

        return $fallback;
    }
}
