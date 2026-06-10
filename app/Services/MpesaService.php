<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    protected string $baseUrl;

    protected string $consumerKey;

    protected string $consumerSecret;

    protected string $shortcode;

    protected string $childStorecode;

    protected string $tillNumber;

    protected string $passkey;

    protected string $callbackUrl;

    protected string $confirmationUrl;

    protected string $validationUrl;

    public function __construct()
    {
        $this->baseUrl = config('mpesa.env') === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';
        $this->consumerKey = (string) config('mpesa.consumer_key');
        $this->consumerSecret = (string) config('mpesa.consumer_secret');
        $this->shortcode = (string) config('mpesa.shortcode');
        $this->childStorecode = (string) config('mpesa.child_storecode');
        $this->tillNumber = (string) config('mpesa.till_number');
        $this->passkey = (string) config('mpesa.passkey');
        $this->callbackUrl = (string) config('mpesa.callback_url');
        $this->confirmationUrl = (string) config('mpesa.confirmation_url');
        $this->validationUrl = (string) config('mpesa.validation_url');
    }

    public function resolvedConfirmationUrl(): string
    {
        return $this->confirmationUrl !== '' ? $this->confirmationUrl : $this->callbackUrl;
    }

    private function makeRequest(string $url, array $headers, ?array $body = null): array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new \RuntimeException('M-Pesa request failed: ' . $error);
        }

        return json_decode((string) $response, true) ?? [];
    }

    public function getAccessToken(): string
    {
        $url = $this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
        $headers = [
            'Authorization: Basic ' . base64_encode($this->consumerKey . ':' . $this->consumerSecret),
        ];
        $response = $this->makeRequest($url, $headers);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Failed to obtain M-Pesa access token.');
        }

        return $response['access_token'];
    }

    public function assertReadyForStkPush(): void
    {
        if ($this->consumerKey === '' || $this->consumerSecret === '') {
            throw new \RuntimeException('M-Pesa API credentials are not configured. Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in .env.');
        }

        if ($this->shortcode === '' || $this->passkey === '') {
            throw new \RuntimeException('M-Pesa pay credentials are not configured. Set MPESA_SHORTCODE and MPESA_PASSKEY in .env.');
        }

        if ($this->tillNumber === '') {
            throw new \RuntimeException('M-Pesa till number is not configured. Set MPESA_TILLNUMBER in .env.');
        }

        if ($this->callbackUrl === '') {
            throw new \RuntimeException('M-Pesa callback URL is not configured. Set MPESA_CALLBACK_URL in .env.');
        }

        $callbackPath = strtolower((string) parse_url($this->callbackUrl, PHP_URL_PATH));
        if (str_contains($callbackPath, 'mpesa')) {
            throw new \RuntimeException(
                'Safaricom rejects STK callback URLs containing the word "mpesa" in the path. Use /api/v1/payments/stk/callback instead.'
            );
        }

        $host = strtolower((string) parse_url($this->callbackUrl, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            throw new \RuntimeException(
                'M-Pesa cannot send STK push while MPESA_CALLBACK_URL points to localhost. Use a public HTTPS URL (for example an ngrok tunnel to /api/v1/payments/stk/callback).'
            );
        }

        if (config('mpesa.env') === 'live' && ! str_starts_with(strtolower($this->callbackUrl), 'https://')) {
            throw new \RuntimeException('Live M-Pesa requires MPESA_CALLBACK_URL to use HTTPS.');
        }
    }

    public function stkPush(string $phone, int $amount, string $accountReference = 'POS Payment'): array
    {
        $this->assertReadyForStkPush();
        $accessToken = $this->getAccessToken();
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->tillNumber,
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->callbackUrl,
            'AccountReference' => $accountReference,
            'TransactionDesc' => 'POS order payment',
        ];

        try {
            $url = $this->baseUrl . '/mpesa/stkpush/v1/processrequest';
            $headers = [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ];

            return $this->makeRequest($url, $headers, $payload);
        } catch (\Throwable $e) {
            Log::error('STK Push Error', ['message' => $e->getMessage()]);

            throw $e;
        }
    }

    public function assertReadyForC2bRegistration(): void
    {
        if ($this->consumerKey === '' || $this->consumerSecret === '') {
            throw new \RuntimeException('M-Pesa API credentials are not configured. Set MPESA_CONSUMER_KEY and MPESA_CONSUMER_SECRET in .env.');
        }

        if ($this->childStorecode === '') {
            throw new \RuntimeException('M-Pesa paybill shortcode is not configured. Set MPESA_CHILD_STORECODE in .env.');
        }

        if ($this->resolvedConfirmationUrl() === '') {
            throw new \RuntimeException('M-Pesa C2B confirmation URL is not configured. Set MPESA_CONFIRMATION_URL in .env.');
        }

        if ($this->validationUrl === '') {
            throw new \RuntimeException('M-Pesa C2B validation URL is not configured. Set MPESA_VALIDATION_URL in .env.');
        }

        foreach ([$this->resolvedConfirmationUrl(), $this->validationUrl] as $endpoint) {
            $path = strtolower((string) parse_url($endpoint, PHP_URL_PATH));
            if (str_contains($path, 'mpesa')) {
                throw new \RuntimeException(
                    'Safaricom rejects C2B URLs containing the word "mpesa" in the path. Use /api/v1/payments/c2b/confirmation and /api/v1/payments/c2b/validation instead.'
                );
            }

            $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
            if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
                throw new \RuntimeException(
                    'M-Pesa C2B URLs must be publicly reachable (not localhost). Point MPESA_CONFIRMATION_URL and MPESA_VALIDATION_URL to your ngrok tunnel.'
                );
            }

            if (config('mpesa.env') === 'live' && ! str_starts_with(strtolower($endpoint), 'https://')) {
                throw new \RuntimeException('Live M-Pesa requires HTTPS C2B confirmation and validation URLs.');
            }
        }
    }

    /**
     * Register paybill/till C2B callback URLs with Safaricom.
     * Direct payments to the till (no STK) are posted to the confirmation URL.
     */
    public function registerUrls(): array
    {
        $this->assertReadyForC2bRegistration();
        $accessToken = $this->getAccessToken();
        $payload = [
            'ShortCode' => $this->childStorecode,
            'ResponseType' => 'Completed',
            'ConfirmationURL' => $this->resolvedConfirmationUrl(),
            'ValidationURL' => $this->validationUrl,
        ];

        $url = $this->baseUrl . '/mpesa/c2b/v2/registerurl';
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ];

        $response = $this->makeRequest($url, $headers, $payload);
        Log::info('M-Pesa C2B register URLs response', [
            'shortcode' => $this->childStorecode,
            'confirmation_url' => $this->resolvedConfirmationUrl(),
            'validation_url' => $this->validationUrl,
            'response' => $response,
        ]);

        return $response;
    }
}
