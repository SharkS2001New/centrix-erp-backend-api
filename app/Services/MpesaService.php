<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Organization;
use App\Services\Erp\CapabilityGate;
use App\Services\Mpesa\MpesaSettingsResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    protected array $config;

    protected string $baseUrl;

    public function __construct(?array $config = null)
    {
        $this->config = MpesaSettingsResolver::normalize($config ?? MpesaSettingsResolver::defaults());
        $this->baseUrl = ($this->config['env'] ?? 'sandbox') === 'sandbox'
            ? 'https://sandbox.safaricom.co.ke'
            : 'https://api.safaricom.co.ke';
    }

    public static function forOrganization(Organization $organization, ?Branch $branch = null): self
    {
        return new self(MpesaSettingsResolver::forBranch($organization, $branch));
    }

    public static function forGate(CapabilityGate $gate, ?Branch $branch = null): self
    {
        $organization = $gate->organization();
        if (! $organization) {
            return new self(MpesaSettingsResolver::forGate($gate));
        }

        return new self(MpesaSettingsResolver::forBranch($organization, $branch));
    }

    public function config(): array
    {
        return $this->config;
    }

    public function resolvedConfirmationUrl(): string
    {
        return MpesaSettingsResolver::resolvedConfirmationUrl($this->config);
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
            'Authorization: Basic ' . base64_encode($this->config['consumer_key'] . ':' . $this->config['consumer_secret']),
        ];
        $response = $this->makeRequest($url, $headers);

        if (empty($response['access_token'])) {
            throw new \RuntimeException('Failed to obtain M-Pesa access token.');
        }

        return $response['access_token'];
    }

    public function assertReadyForStkPush(): void
    {
        MpesaSettingsResolver::assertReadyForStkPush($this->config);
    }

    public function stkPush(string $phone, int $amount, string $accountReference = 'POS Payment'): array
    {
        $this->assertReadyForStkPush();
        $accessToken = $this->getAccessToken();
        $timestamp = Carbon::now()->format('YmdHis');
        $password = base64_encode($this->config['shortcode'] . $this->config['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->config['shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerBuyGoodsOnline',
            'Amount' => $amount,
            'PartyA' => $phone,
            'PartyB' => $this->config['till_number'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $this->config['stk_callback_url'],
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
}
