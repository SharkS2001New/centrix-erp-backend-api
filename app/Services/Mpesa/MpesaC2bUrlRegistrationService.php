<?php

namespace App\Services\Mpesa;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MpesaC2bUrlRegistrationService
{
    /**
     * @param  array<string, mixed>  $config
     * @return array{response_code: string, response_description: string, originator_conversation_id: ?string, conversation_id: ?string}
     */
    public function register(array $config): array
    {
        MpesaSettingsResolver::assertReadyForC2bRegistration($config);

        $shortCode = trim((string) (
            $config['child_storecode']
            ?: $config['till_number']
            ?: $config['shortcode']
        ));
        if ($shortCode === '') {
            throw new RuntimeException('C2B paybill / till shortcode is required.');
        }

        $baseUrl = ($config['env'] ?? 'sandbox') === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';

        try {
            $tokenResponse = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->withBasicAuth($config['consumer_key'], $config['consumer_secret'])
                ->get($baseUrl.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);

            $tokenBody = $tokenResponse->json();
            if (! $tokenResponse->successful() || ! is_array($tokenBody) || empty($tokenBody['access_token'])) {
                throw new RuntimeException($this->providerError($tokenBody, 'Daraja did not return an access token.'));
            }

            $registrationResponse = Http::acceptJson()
                ->connectTimeout(2)
                ->timeout(4)
                ->withToken($tokenBody['access_token'])
                ->post($baseUrl.'/mpesa/c2b/v1/registerurl', [
                    'ShortCode' => $shortCode,
                    'ResponseType' => 'Completed',
                    'ConfirmationURL' => $config['c2b_confirmation_url'],
                    'ValidationURL' => $config['c2b_validation_url'],
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Unable to connect to Safaricom Daraja. Please retry.', previous: $e);
        }

        $body = $registrationResponse->json();
        $responseCode = is_array($body) ? (string) ($body['ResponseCode'] ?? '') : '';
        if (! $registrationResponse->successful() || $responseCode !== '0') {
            throw new RuntimeException($this->providerError($body, 'Daraja could not register the C2B URLs.'));
        }

        return [
            'response_code' => $responseCode,
            'response_description' => (string) ($body['ResponseDescription'] ?? 'C2B URLs registered successfully.'),
            'originator_conversation_id' => isset($body['OriginatorCoversationID'])
                ? (string) $body['OriginatorCoversationID']
                : null,
            'conversation_id' => isset($body['ConversationID']) ? (string) $body['ConversationID'] : null,
        ];
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
