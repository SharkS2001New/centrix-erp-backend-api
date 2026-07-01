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
            ['id' => 'modern', 'label' => 'Modern', 'description' => 'Clean layout with accent header — Stripe-inspired.'],
            ['id' => 'classic', 'label' => 'Classic', 'description' => 'Traditional bordered invoice with formal typography.'],
            ['id' => 'minimal', 'label' => 'Minimal', 'description' => 'Generous whitespace and subtle dividers.'],
            ['id' => 'corporate', 'label' => 'Corporate', 'description' => 'Navy header band suited for enterprise clients.'],
            ['id' => 'bold', 'label' => 'Bold', 'description' => 'Large headings and high-contrast totals.'],
            ['id' => 'elegant', 'label' => 'Elegant', 'description' => 'Refined serif accents — FreshBooks style.'],
            ['id' => 'stripe', 'label' => 'Stripe', 'description' => 'Purple accent sidebar — popular SaaS billing look.'],
            ['id' => 'compact', 'label' => 'Compact', 'description' => 'Dense layout for printing multiple copies.'],
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
                'name' => 'Centrix ERP',
                'email' => config('mail.from.address', 'billing@centrixerp.com'),
                'phone' => '',
                'address' => 'Nairobi, Kenya',
                'tax_pin' => '',
            ];
        }

        return [
            'name' => $org->org_name,
            'email' => $org->org_email,
            'phone' => $org->primary_tel,
            'address' => $org->org_address,
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
