<?php

namespace App\Services\Mpesa;

use App\Models\Branch;
use App\Models\Organization;
use App\Services\Erp\CapabilityGate;

class MpesaSettingsResolver
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return config('erp.module_settings_defaults.finance.mpesa', []);
    }

    /** @return array<string, mixed> */
    public static function forOrganization(Organization $organization): array
    {
        $finance = $organization->module_settings['finance'] ?? [];
        $mpesa = array_merge(self::defaults(), is_array($finance['mpesa'] ?? null) ? $finance['mpesa'] : []);

        return self::normalize($mpesa);
    }

    /** @return array<string, mixed> */
    public static function forGate(CapabilityGate $gate): array
    {
        $finance = $gate->moduleSettings('finance');
        $mpesa = array_merge(self::defaults(), is_array($finance['mpesa'] ?? null) ? $finance['mpesa'] : []);

        return self::normalize($mpesa);
    }

    /** @return array<string, mixed> */
    public static function forBranch(Organization $organization, ?Branch $branch): array
    {
        $config = self::forOrganization($organization);
        if (! $branch) {
            return $config;
        }

        $branchMpesa = is_array($branch->settings['mpesa'] ?? null) ? $branch->settings['mpesa'] : [];
        foreach (['env', 'consumer_key', 'consumer_secret', 'shortcode', 'till_number', 'child_storecode', 'passkey', 'stk_callback_url', 'c2b_confirmation_url', 'c2b_validation_url'] as $key) {
            $value = $branchMpesa[$key] ?? null;
            if ($value !== null && $value !== '') {
                $config[$key] = $value;
            }
        }

        return self::normalize($config);
    }

    /** @param  array<string, mixed>  $mpesa */
    public static function normalize(array $mpesa): array
    {
        $out = array_merge(self::defaults(), $mpesa);
        $out['env'] = in_array($out['env'] ?? 'sandbox', ['sandbox', 'live'], true) ? $out['env'] : 'sandbox';
        $out['enable_stk_push'] = filter_var($out['enable_stk_push'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $out['enable_c2b_reconciliation'] = filter_var($out['enable_c2b_reconciliation'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $out['auto_apply_order_reference'] = filter_var($out['auto_apply_order_reference'] ?? true, FILTER_VALIDATE_BOOLEAN);
        $out['payment_account_hint'] = trim((string) ($out['payment_account_hint'] ?? 'Enter your order number (e.g. S12)'));
        foreach (['consumer_key', 'consumer_secret', 'shortcode', 'till_number', 'child_storecode', 'passkey', 'stk_callback_url', 'c2b_confirmation_url', 'c2b_validation_url'] as $key) {
            $out[$key] = trim((string) ($out[$key] ?? ''));
        }

        return $out;
    }

    /** @param  array<string, mixed>  $config */
    public static function resolvedConfirmationUrl(array $config): string
    {
        return $config['c2b_confirmation_url'] !== ''
            ? $config['c2b_confirmation_url']
            : $config['stk_callback_url'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{env: string, shortcode: string, confirmation_url: string, validation_url: string, stk_callback_url: string, ready: bool, issues: string[]}
     */
    public static function describe(array $config): array
    {
        $issues = [];
        try {
            self::assertReadyForC2b($config);
            $ready = true;
        } catch (\RuntimeException $e) {
            $ready = false;
            $issues[] = $e->getMessage();
        }

        return [
            'env' => $config['env'] ?? 'sandbox',
            'shortcode' => $config['child_storecode'] ?: $config['till_number'] ?: $config['shortcode'],
            'confirmation_url' => self::resolvedConfirmationUrl($config),
            'validation_url' => $config['c2b_validation_url'] ?? '',
            'stk_callback_url' => $config['stk_callback_url'] ?? '',
            'stk_push_enabled' => self::isStkPushEnabled($config),
            'c2b_reconciliation_enabled' => self::isC2bReconciliationEnabled($config),
            'auto_apply_order_reference' => self::isAutoApplyOrderReferenceEnabled($config),
            'payment_account_hint' => self::paymentAccountHint($config),
            'ready' => $ready,
            'issues' => $issues,
        ];
    }

    /** @param  array<string, mixed>  $config */
    public static function isC2bReconciliationEnabled(array $config): bool
    {
        return filter_var($config['enable_c2b_reconciliation'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isC2bReconciliationEnabledForOrganization(Organization $organization): bool
    {
        return self::isC2bReconciliationEnabled(self::forOrganization($organization));
    }

    /** @param  array<string, mixed>  $config */
    public static function isAutoApplyOrderReferenceEnabled(array $config): bool
    {
        if (! self::isC2bReconciliationEnabled($config)) {
            return false;
        }

        return filter_var($config['auto_apply_order_reference'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isAutoApplyOrderReferenceEnabledForOrganization(Organization $organization): bool
    {
        return self::isAutoApplyOrderReferenceEnabled(self::forOrganization($organization));
    }

    /** @param  array<string, mixed>  $config */
    public static function paymentAccountHint(array $config): string
    {
        $hint = trim((string) ($config['payment_account_hint'] ?? ''));

        return $hint !== '' ? $hint : 'Enter your order number (e.g. S12)';
    }

    public static function paymentAccountHintForOrganization(Organization $organization): string
    {
        return self::paymentAccountHint(self::forOrganization($organization));
    }

    /** @param  array<string, mixed>  $config */
    public static function isStkPushEnabled(array $config): bool
    {
        return filter_var($config['enable_stk_push'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    public static function isStkPushEnabledForOrganization(Organization $organization): bool
    {
        $gate = app(CapabilityGate::class)->forOrganization($organization);
        if (! $gate->mpesaStkPlatformEnabled()) {
            return false;
        }

        return self::isStkPushEnabled(self::forOrganization($organization));
    }

    public static function assertStkPushEnabledForOrganization(Organization $organization): void
    {
        if (! self::isStkPushEnabledForOrganization($organization)) {
            throw new \RuntimeException('STK push is disabled for this organization. Enable it under Admin → Settings → Finance.');
        }
    }

    /** @param  array<string, mixed>  $config */
    public static function assertReadyForStkPush(array $config): void
    {
        if (! self::isStkPushEnabled($config)) {
            throw new \RuntimeException('STK push is disabled for this organization. Enable it under Admin → Settings → Finance.');
        }

        if (($config['consumer_key'] ?? '') === '' || ($config['consumer_secret'] ?? '') === '') {
            throw new \RuntimeException('M-Pesa consumer key and secret are required in organization finance settings.');
        }

        if (($config['shortcode'] ?? '') === '' || ($config['passkey'] ?? '') === '') {
            throw new \RuntimeException('M-Pesa paybill shortcode and passkey are required in organization finance settings.');
        }

        if (($config['till_number'] ?? '') === '') {
            throw new \RuntimeException('M-Pesa till number is required in organization finance settings.');
        }

        if (($config['stk_callback_url'] ?? '') === '') {
            throw new \RuntimeException('STK callback URL is required. Set it under Finance settings (same URL you register on Daraja).');
        }

        self::assertPublicHttpsUrl($config['stk_callback_url'], 'STK callback URL', $config['env'] ?? 'sandbox');
    }

    /** @param  array<string, mixed>  $config */
    public static function assertReadyForC2b(array $config): void
    {
        if (($config['consumer_key'] ?? '') === '' || ($config['consumer_secret'] ?? '') === '') {
            throw new \RuntimeException('M-Pesa consumer key and secret are required in organization finance settings.');
        }

        if (($config['child_storecode'] ?? '') === '' && ($config['till_number'] ?? '') === '') {
            throw new \RuntimeException('C2B paybill / till shortcode is required in organization finance settings.');
        }

        if (self::resolvedConfirmationUrl($config) === '') {
            throw new \RuntimeException('C2B confirmation URL is required. Enter the URL registered on the Safaricom Daraja portal.');
        }

        if (($config['c2b_validation_url'] ?? '') === '') {
            throw new \RuntimeException('C2B validation URL is required. Enter the URL registered on the Safaricom Daraja portal.');
        }

        foreach ([self::resolvedConfirmationUrl($config), $config['c2b_validation_url']] as $endpoint) {
            self::assertPublicHttpsUrl($endpoint, 'C2B callback URL', $config['env'] ?? 'sandbox');
        }
    }

    protected static function assertPublicHttpsUrl(string $endpoint, string $label, string $env): void
    {
        $path = strtolower((string) parse_url($endpoint, PHP_URL_PATH));
        if (str_contains($path, 'mpesa')) {
            throw new \RuntimeException(
                "{$label} must not contain the word \"mpesa\" in the path. Use /api/v1/payments/c2b/confirmation or /api/v1/payments/stk/callback.",
            );
        }

        $host = strtolower((string) parse_url($endpoint, PHP_URL_HOST));
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            throw new \RuntimeException("{$label} must be publicly reachable (not localhost).");
        }

        if ($env === 'live' && ! str_starts_with(strtolower($endpoint), 'https://')) {
            throw new \RuntimeException("Live M-Pesa requires {$label} to use HTTPS.");
        }
    }

    public static function organizationIdForC2bPayload(array $payload): ?int
    {
        $shortCode = trim((string) (
            $payload['BusinessShortCode']
            ?? $payload['business_short_code']
            ?? ''
        ));

        if ($shortCode === '') {
            return null;
        }

        $organizations = Organization::query()->get(['id', 'module_settings']);
        foreach ($organizations as $org) {
            $mpesa = self::forOrganization($org);
            $codes = array_filter([
                $mpesa['child_storecode'] ?? '',
                $mpesa['till_number'] ?? '',
                $mpesa['shortcode'] ?? '',
            ]);
            if (in_array($shortCode, $codes, true)) {
                return (int) $org->id;
            }

            $branches = Branch::query()->where('organization_id', $org->id)->get(['id', 'settings']);
            foreach ($branches as $branch) {
                $branchMpesa = self::forBranch($org, $branch);
                $branchCodes = array_filter([
                    $branchMpesa['child_storecode'] ?? '',
                    $branchMpesa['till_number'] ?? '',
                    $branchMpesa['shortcode'] ?? '',
                ]);
                if (in_array($shortCode, $branchCodes, true)) {
                    return (int) $org->id;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $mpesa */
    public static function maskForClient(array $mpesa): array
    {
        $out = self::normalize($mpesa);
        if ($out['consumer_secret'] !== '') {
            $out['consumer_secret'] = '********';
        }
        if ($out['passkey'] !== '') {
            $out['passkey'] = '********';
        }

        return $out;
    }

    /** @param  array<string, mixed>  $incoming */
    public static function mergeFinanceMpesa(array $current, array $incoming): array
    {
        $next = array_merge(self::defaults(), is_array($current['mpesa'] ?? null) ? $current['mpesa'] : [], $incoming);
        $existing = is_array($current['mpesa'] ?? null) ? $current['mpesa'] : [];

        if (($incoming['consumer_secret'] ?? '') === '' && ! empty($existing['consumer_secret'])) {
            $next['consumer_secret'] = $existing['consumer_secret'];
        }
        if (($incoming['passkey'] ?? '') === '' && ! empty($existing['passkey'])) {
            $next['passkey'] = $existing['passkey'];
        }

        return ['mpesa' => self::normalize($next)];
    }
}
