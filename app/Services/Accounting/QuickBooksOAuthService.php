<?php

namespace App\Services\Accounting;

use App\Models\AccountingConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QuickBooksOAuthService
{
    public function authorizationUrl(int $orgId, int $userId): string
    {
        $config = QuickBooksSettingsResolver::forOrganization($orgId);
        $clientId = $config['client_id'];
        if (! $clientId) {
            throw ValidationException::withMessages([
                'quickbooks' => ['QuickBooks Client ID is not configured. Add it under Admin → Settings → Finance.'],
            ]);
        }

        $state = Str::random(40);
        Cache::put($this->stateCacheKey($state), [
            'organization_id' => $orgId,
            'user_id' => $userId,
        ], now()->addMinutes(15));

        $query = http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'scope' => $config['scope'],
            'redirect_uri' => $config['redirect_uri'],
            'state' => $state,
        ]);

        return $config['authorization_url'].'?'.$query;
    }

    /** @return array{connection: AccountingConnection, redirect_url: string} */
    public function handleCallback(string $code, string $state, ?string $realmId): array
    {
        $payload = Cache::pull($this->stateCacheKey($state));
        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'state' => ['OAuth state is invalid or expired.'],
            ]);
        }

        $orgId = (int) $payload['organization_id'];
        $userId = (int) $payload['user_id'];

        $tokens = $this->exchangeAuthorizationCode($orgId, $code);

        $connection = AccountingConnection::updateOrCreate(
            [
                'organization_id' => $orgId,
                'provider' => 'quickbooks',
            ],
            [
                'realm_id' => $realmId,
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'] ?? null,
                'token_expires_at' => isset($tokens['expires_in'])
                    ? now()->addSeconds((int) $tokens['expires_in'])
                    : null,
                'status' => 'connected',
                'last_error' => null,
                'connected_at' => now(),
                'connected_by' => $userId,
            ],
        );

        $frontend = rtrim(config('erp.frontend_url'), '/');

        return [
            'connection' => $connection,
            'redirect_url' => $frontend.'/admin/settings?quickbooks=connected',
        ];
    }

    public function disconnect(int $orgId): void
    {
        AccountingConnection::query()
            ->where('organization_id', $orgId)
            ->where('provider', 'quickbooks')
            ->update([
                'status' => 'disconnected',
                'access_token' => null,
                'refresh_token' => null,
                'token_expires_at' => null,
                'realm_id' => null,
                'last_error' => null,
            ]);
    }

    public function connectionForOrg(int $orgId): ?AccountingConnection
    {
        return AccountingConnection::query()
            ->where('organization_id', $orgId)
            ->where('provider', 'quickbooks')
            ->first();
    }

    public function ensureFreshToken(AccountingConnection $connection): AccountingConnection
    {
        if ($connection->status !== 'connected') {
            throw ValidationException::withMessages([
                'quickbooks' => ['QuickBooks is not connected.'],
            ]);
        }

        if (
            $connection->token_expires_at
            && $connection->token_expires_at->isFuture()
            && $connection->access_token
        ) {
            return $connection;
        }

        if (! $connection->refresh_token) {
            if ($connection->access_token) {
                return $connection;
            }

            throw ValidationException::withMessages([
                'quickbooks' => ['QuickBooks refresh token is missing. Reconnect QuickBooks.'],
            ]);
        }

        $config = QuickBooksSettingsResolver::forOrganization((int) $connection->organization_id);
        $clientId = $config['client_id'];
        $clientSecret = $config['client_secret'];
        if (! $clientId || ! $clientSecret) {
            return $connection;
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post($config['token_url'], [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

        if (! $response->successful()) {
            $connection->forceFill([
                'status' => 'error',
                'last_error' => 'Token refresh failed: '.$response->body(),
            ])->save();

            throw ValidationException::withMessages([
                'quickbooks' => ['QuickBooks token refresh failed. Reconnect QuickBooks.'],
            ]);
        }

        $tokens = $response->json();
        $connection->forceFill([
            'access_token' => $tokens['access_token'] ?? $connection->access_token,
            'refresh_token' => $tokens['refresh_token'] ?? $connection->refresh_token,
            'token_expires_at' => isset($tokens['expires_in'])
                ? now()->addSeconds((int) $tokens['expires_in'])
                : now()->addHour(),
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        return $connection->fresh();
    }

    /** @return array{access_token: string, refresh_token?: string, expires_in?: int} */
    protected function exchangeAuthorizationCode(int $orgId, string $code): array
    {
        $config = QuickBooksSettingsResolver::forOrganization($orgId);
        $clientId = $config['client_id'];
        $clientSecret = $config['client_secret'];

        if (! $clientId || ! $clientSecret) {
            return [
                'access_token' => 'stub-access-'.Str::random(24),
                'refresh_token' => 'stub-refresh-'.Str::random(24),
                'expires_in' => 3600,
            ];
        }

        $response = Http::asForm()
            ->withBasicAuth($clientId, $clientSecret)
            ->post($config['token_url'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $config['redirect_uri'],
            ]);

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'quickbooks' => ['QuickBooks token exchange failed: '.$response->body()],
            ]);
        }

        return $response->json();
    }

    protected function stateCacheKey(string $state): string
    {
        return 'quickbooks_oauth_state:'.$state;
    }
}
