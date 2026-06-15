<?php

namespace App\Services\Accounting;

use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class QuickBooksSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.finance.quickbooks', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization|int $organization): array
    {
        $org = $organization instanceof Organization
            ? $organization
            : Organization::findOrFail((int) $organization);

        $finance = $org->module_settings['finance'] ?? [];
        $stored = is_array($finance['quickbooks'] ?? null) ? $finance['quickbooks'] : [];

        return self::resolve($stored);
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        $finance = $gate->moduleSettings('finance');
        $stored = is_array($finance['quickbooks'] ?? null) ? $finance['quickbooks'] : [];

        return self::resolve($stored);
    }

    /** @param  array<string, mixed>  $stored */
    public static function resolve(array $stored): array
    {
        $merged = array_merge(self::defaults(), $stored);

        $clientId = trim((string) ($merged['client_id'] ?? ''));
        $clientSecret = trim((string) ($merged['client_secret'] ?? ''));
        $redirectUri = trim((string) ($merged['redirect_uri'] ?? ''));
        $environment = ($merged['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';

        if ($clientId === '') {
            $clientId = trim((string) config('quickbooks.client_id', ''));
        }
        if ($clientSecret === '') {
            $clientSecret = trim((string) config('quickbooks.client_secret', ''));
        }
        if ($redirectUri === '') {
            $redirectUri = trim((string) config('quickbooks.redirect_uri', ''));
            if ($redirectUri === '') {
                $redirectUri = rtrim((string) config('app.url'), '/').'/api/v1/accounting/quickbooks/callback';
            }
        }

        if ($environment === 'sandbox' && config('quickbooks.environment') === 'production') {
            // Keep org choice; env only used when org environment not set in stored
        }
        if (! isset($stored['environment']) && config('quickbooks.environment') === 'production') {
            $environment = 'production';
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'environment' => $environment,
            'authorization_url' => config('quickbooks.authorization_url'),
            'token_url' => config('quickbooks.token_url'),
            'scope' => config('quickbooks.scope'),
            'api_base_url' => $environment === 'production'
                ? 'https://quickbooks.api.intuit.com'
                : 'https://sandbox-quickbooks.api.intuit.com',
        ];
    }

    /** @param  array<string, mixed>  $config */
    public static function describe(array $config): array
    {
        $issues = [];
        if ($config['client_id'] === '') {
            $issues[] = 'QuickBooks Client ID is not configured.';
        }
        if ($config['client_secret'] === '') {
            $issues[] = 'QuickBooks Client Secret is not configured.';
        }
        if ($config['redirect_uri'] === '') {
            $issues[] = 'QuickBooks redirect URI is not configured.';
        }

        return [
            'environment' => $config['environment'] ?? 'sandbox',
            'redirect_uri' => $config['redirect_uri'] ?? '',
            'client_id_set' => ($config['client_id'] ?? '') !== '',
            'client_secret_set' => ($config['client_secret'] ?? '') !== '',
            'ready' => $issues === [],
            'issues' => $issues,
            'source' => self::credentialSource($config),
        ];
    }

    /** @param  array<string, mixed>  $stored */
    public static function maskStoredForClient(array $stored): array
    {
        $out = array_merge(self::defaults(), $stored);
        if (trim((string) ($out['client_secret'] ?? '')) !== '') {
            $out['client_secret'] = '********';
        }

        return $out;
    }

    /** @param  array<string, mixed>  $config */
    public static function maskForClient(array $config): array
    {
        $out = $config;
        if (($out['client_secret'] ?? '') !== '') {
            $out['client_secret'] = '********';
        }

        return $out;
    }

    /** @param  array<string, mixed>  $incoming */
    public static function mergeFinanceQuickBooks(array $current, array $incoming): array
    {
        $next = array_merge(
            self::defaults(),
            is_array($current['quickbooks'] ?? null) ? $current['quickbooks'] : [],
            $incoming,
        );
        $existing = is_array($current['quickbooks'] ?? null) ? $current['quickbooks'] : [];

        $secret = $incoming['client_secret'] ?? null;
        if (($secret === '' || $secret === '********') && ! empty($existing['client_secret'])) {
            $next['client_secret'] = $existing['client_secret'];
        }

        $next['client_id'] = trim((string) ($next['client_id'] ?? ''));
        $next['redirect_uri'] = trim((string) ($next['redirect_uri'] ?? ''));
        $next['environment'] = ($next['environment'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox';

        return ['quickbooks' => $next];
    }

    /** @param  array<string, mixed>  $config */
    protected static function credentialSource(array $config): string
    {
        $envHas = config('quickbooks.client_id') && config('quickbooks.client_secret');

        return $envHas ? 'env_or_finance' : 'finance';
    }
}
