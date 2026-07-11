<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Services\Erp\ApplicationProvisioner;
use App\Services\Erp\CapabilityGate;

class PlatformInvoiceBillingService
{
    public function __construct(
        protected ApplicationProvisioner $applications,
    ) {}

    /** @return list<array<string, mixed>> */
    public function builtInDesignTemplates(): array
    {
        return [
            ['id' => 'modern', 'label' => 'Modern', 'description' => 'Clean layout with blue accent header — Stripe-inspired.'],
            ['id' => 'classic', 'label' => 'Classic', 'description' => 'Traditional bordered invoice with formal serif typography.'],
            ['id' => 'minimal', 'label' => 'Minimal', 'description' => 'Quiet whitespace and subtle dividers — no chrome.'],
            ['id' => 'corporate', 'label' => 'Corporate', 'description' => 'Solid navy header band suited for enterprise clients.'],
            ['id' => 'bold', 'label' => 'Bold', 'description' => 'High-contrast red accents and large totals.'],
            ['id' => 'elegant', 'label' => 'Elegant', 'description' => 'Warm serif accents — FreshBooks / boutique style.'],
            ['id' => 'stripe', 'label' => 'Stripe', 'description' => 'Purple accent sidebar — popular SaaS billing look.'],
            ['id' => 'compact', 'label' => 'Compact', 'description' => 'Dense layout for printing multiple copies.'],
            ['id' => 'ocean', 'label' => 'Ocean', 'description' => 'Teal accents with a calm coastal feel.'],
            ['id' => 'forest', 'label' => 'Forest', 'description' => 'Deep green header for eco / agri brands.'],
            ['id' => 'sunset', 'label' => 'Sunset', 'description' => 'Warm orange accents — energetic retail look.'],
            ['id' => 'slate', 'label' => 'Slate', 'description' => 'Neutral grey professional stationery.'],
            ['id' => 'rose', 'label' => 'Rose', 'description' => 'Soft rose accents for lifestyle brands.'],
            ['id' => 'indigo', 'label' => 'Indigo', 'description' => 'Deep indigo band — tech / SaaS friendly.'],
            ['id' => 'gold', 'label' => 'Gold', 'description' => 'Premium gold accents with ivory background.'],
            ['id' => 'paper', 'label' => 'Paper', 'description' => 'Cream paper feel with classic rule lines.'],
            ['id' => 'ledger', 'label' => 'Ledger', 'description' => 'Accounting-style ruled rows and charcoal type.'],
            ['id' => 'midnight', 'label' => 'Midnight', 'description' => 'Dark midnight header with crisp white type.'],
            ['id' => 'emerald', 'label' => 'Emerald', 'description' => 'Bright emerald accents — growth / finance.'],
            ['id' => 'mono', 'label' => 'Mono', 'description' => 'Monospace-inspired type for ops / logistics.'],
            ['id' => 'coastal', 'label' => 'Coastal', 'description' => 'Sky blue top bar and airy spacing.'],
            ['id' => 'graphite', 'label' => 'Graphite', 'description' => 'Matte graphite header — industrial polish.'],
            ['id' => 'ivory', 'label' => 'Ivory', 'description' => 'Soft ivory sheet with chocolate brown accents.'],
            ['id' => 'magenta', 'label' => 'Magenta', 'description' => 'Vivid magenta accent for creative agencies.'],
            ['id' => 'safari', 'label' => 'Safari', 'description' => 'Earth-tone brown accents — East Africa inspired.'],
            ['id' => 'rounded', 'label' => 'Rounded', 'description' => 'Friendly rounded sheet with soft sky accents.'],
        ];
    }

    public function platformSeller(): array
    {
        $platformCode = config('erp.platform_company_code', 'PLATFORM');
        $org = Organization::query()
            ->where('company_code', $platformCode)
            ->first();

        if (! $org) {
            return [
                'name' => 'CentrixERP',
                'email' => config('mail.from.address', 'billing@centrixerp.com'),
                'phone' => '',
                'address' => 'Nairobi, Kenya',
                'tax_pin' => '',
            ];
        }

        return [
            'name' => 'CentrixERP',
            'email' => $org->org_email ?: config('mail.from.address', 'billing@centrixerp.com'),
            'phone' => $org->primary_tel,
            'address' => $org->org_address ?: 'Nairobi, Kenya',
            'tax_pin' => $org->org_pin,
        ];
    }

    /**
     * @return array{
     *   organization: array<string, mixed>|null,
     *   bill_to: array<string, mixed>,
     *   applications: list<array<string, mixed>>,
     *   module_summaries: list<array<string, mixed>>,
     *   seller: array<string, mixed>
     * }
     */
    public function billingContext(?Organization $organization): array
    {
        $billTo = [
            'name' => '',
            'email' => '',
            'phone' => '',
            'address' => '',
            'tax_pin' => '',
            'company_code' => '',
        ];

        $applications = [];
        $moduleSummaries = $this->allModuleSummaries();

        if ($organization) {
            $billTo = [
                'name' => $organization->org_name,
                'email' => $organization->org_email,
                'phone' => $organization->primary_tel,
                'address' => $organization->org_address,
                'tax_pin' => $organization->org_pin,
                'company_code' => $organization->company_code,
            ];

            $gate = app(CapabilityGate::class)->forOrganization($organization);
            $modules = $gate->allModules();
            $applications = $this->applications->applicationsFromEnabledModules($modules);

            $moduleSummaries = array_values(array_filter(
                $moduleSummaries,
                fn (array $row) => $this->moduleSummaryEnabledForOrg($row, $modules, $gate),
            ));
        }

        return [
            'organization' => $organization?->only([
                'id', 'company_code', 'org_name', 'org_email', 'deployment_profile', 'is_active',
            ]),
            'bill_to' => $billTo,
            'applications' => $applications,
            'module_summaries' => $moduleSummaries,
            'seller' => $this->platformSeller(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function allModuleSummaries(): array
    {
        $config = config('platform_billing.modules', []);
        $out = [];

        foreach ($config as $key => $meta) {
            if (($meta['billable'] ?? true) === false) {
                continue;
            }
            $out[] = [
                'key' => $key,
                'label' => $meta['label'] ?? $key,
                'description' => $meta['description'] ?? '',
                'default_amount' => (float) ($meta['default_amount'] ?? 0),
                'billing_period' => $meta['billing_period'] ?? 'monthly',
                'platform_flag' => $meta['platform_flag'] ?? null,
                'always_available' => (bool) ($meta['always_available'] ?? false),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, bool>  $modules
     */
    protected function moduleSummaryEnabledForOrg(array $row, array $modules, CapabilityGate $gate): bool
    {
        if (! empty($row['always_available'])) {
            return true;
        }

        $flag = $row['platform_flag'] ?? null;
        if ($flag === 'ai') {
            return $gate->aiPlatformEnabled();
        }
        if ($flag === 'kra') {
            return $gate->kraIntegrationPlatformEnabled();
        }
        if ($flag === 'mpesa') {
            return $gate->mpesaStkPlatformEnabled();
        }
        if ($flag === 'advanced_import') {
            return $gate->advancedDataImportPlatformEnabled();
        }

        $key = (string) $row['key'];
        if (str_starts_with($key, 'platform.')) {
            return false;
        }

        if (($modules[$key] ?? false) === true) {
            return true;
        }

        $parent = explode('.', $key)[0] ?? $key;

        return ($modules[$parent] ?? false) === true && ! str_contains($key, '.');
    }

    /**
     * @param  list<array<string, mixed>>  $lineItems
     * @return array{subtotal: float, tax_amount: float, total: float}
     */
    public function calculateTotals(array $lineItems, float $taxRate): array
    {
        $subtotal = 0.0;
        foreach ($lineItems as $item) {
            if (($item['included'] ?? true) === false) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? 1);
            $unit = (float) ($item['unit_price'] ?? 0);
            $amount = isset($item['amount']) ? (float) $item['amount'] : round($qty * $unit, 2);
            $subtotal += $amount;
        }

        $subtotal = round($subtotal, 2);
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $total = round($subtotal + $taxAmount, 2);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total' => $total,
        ];
    }

    public function nextInvoiceNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "PLT-{$year}-";
        $latest = \App\Models\PlatformInvoice::query()
            ->where('invoice_number', 'like', "{$prefix}%")
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $seq = 1;
        if ($latest && preg_match('/-(\d+)$/', $latest, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }
}
