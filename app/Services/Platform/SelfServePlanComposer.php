<?php

namespace App\Services\Platform;

use App\Exceptions\Ai\AiProviderException;
use App\Models\PlatformPlan;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\BackofficeSections;
use Illuminate\Validation\ValidationException;

/**
 * Turns a platform admin's written description into one published self-serve plan.
 */
class SelfServePlanComposer
{
    /** @var array<string, string> */
    public const WORKSPACES = [
        'pos' => 'External POS',
        'backoffice' => 'Backoffice',
        'hotel_bar_pos' => 'Hotel POS',
        'hospitality_backoffice' => 'Hotel Backoffice',
        'distribution' => 'Distribution',
        'accounting' => 'Accounting',
        'hr' => 'Human Resources',
        'admin' => 'Administration',
    ];

    /** @var array<string, string> */
    public const SECTIONS = [
        'dashboard' => 'Dashboard',
        'products' => 'Products',
        'pos' => 'Till operations',
        'pricing_tax' => 'Pricing & tax',
        'sales_orders' => 'Sales & orders',
        'shop_debtors' => 'Shop debtors',
        'field_sales' => 'Field sales',
        'after_sales' => 'After sales',
        'promotions' => 'Promotions & loyalty',
        'customers' => 'Customers',
        'expenses' => 'Expenses',
        'investors' => 'Investors',
        'inventory' => 'Inventory',
        'stock_movements' => 'Stock movements',
        'suppliers' => 'Suppliers & purchases',
        'reports' => 'Reports',
    ];

    public function __construct(protected AiProviderFactory $providers) {}

    /**
     * Centrix already knows its own applications. The admin only writes a short line.
     */
    public function systemPrompt(): string
    {
        $apps = [];
        foreach (self::WORKSPACES as $key => $label) {
            $detail = (string) (config('erp_applications.definitions.'.$key.'.description') ?? '');
            $apps[] = $key.' ('.$label.'): '.$detail;
        }
        $sections = [];
        foreach (self::SECTIONS as $key => $label) {
            $sections[] = $key.' ('.$label.')';
        }

        return 'You create one Centrix ERP self-serve subscription plan from a short description. '
            .'Centrix is a Kenya business system sold per organisation. Retail, wholesale, and distribution share one set of applications. Hotel is a separate industry and must not be mixed with retail unless the description asks for both. '
            .'A short line is enough. You decide the name, the card text, the applications, and the Backoffice sections from what that kind of business needs. Do not wait for the administrator to list screens. '
            .'Use the price they wrote, in KES. If they did not write a price, set price to 0. interval is yearly only when they say year, yearly, or annual; otherwise monthly. '
            .'External POS (pos) is the shop till and also needs backoffice. Hotel POS (hotel_bar_pos) is the bar, restaurant, and room charge and also needs hospitality_backoffice. Do not give a shop plan Hotel POS, and do not give a hotel plan External POS or retail Backoffice. '
            .'A small shop gets the till, products, sales, customers, inventory, suppliers, and reports. Add distribution only for delivery or wholesale dispatch, accounting only for books or the ledger, and hr only for staff or payroll. Administration (admin) is for users and branches when the description is a normal operating plan. '
            .'Return JSON only, no markdown. Shape: {"name":"","description":"","interval":"monthly|yearly","price":0,"features":[{"label":"","detail":""}],"workspace_keys":[],"section_keys":[]}. '
            .'workspace_keys must be chosen from: '.implode(' ', $apps).' '
            .'section_keys must be chosen from: '.implode(', ', $sections).'. '
            .'features are a few short lines the organisation will read on the plan card.';
    }

    /**
     * @return array{plan: PlatformPlan, summary: array<string, mixed>}
     */
    public function createFromWords(string $words): array
    {
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime || empty($runtime['api_key'])) {
            throw ValidationException::withMessages([
                'description' => ['No Gemini or OpenAI key is saved for Centrix AI. Add one under Platform → AI training → Credentials.'],
            ]);
        }

        $system = $this->systemPrompt();

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => $system,
                'messages' => [
                    ['role' => 'user', 'content' => $words],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 1200,
            ]);
        } catch (AiProviderException $e) {
            throw ValidationException::withMessages([
                'description' => ['Centrix AI could not draft this plan. '.$e->getMessage()],
            ]);
        }

        $raw = trim((string) ($turn['text'] ?? ''));
        $parsed = json_decode($raw, true);
        if (! is_array($parsed) && preg_match('/\{[\s\S]*\}/', $raw, $match)) {
            $parsed = json_decode($match[0], true);
        }
        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'description' => ['Centrix AI did not return a plan. Try a short line such as “Small shop, 2000 a month”.'],
            ]);
        }

        $draft = $this->normalize($parsed);
        $yearly = $draft['interval'] === 'yearly';
        $plan = PlatformPlan::query()->create([
            'name' => $draft['name'],
            'description' => $draft['description'],
            'interval' => $yearly ? 'annual' : 'monthly',
            'license_basis' => 'org',
            'currency' => 'KES',
            'price' => $draft['price'],
            'first_payment_price' => $draft['price'],
            'renewal_price' => $draft['price'],
            'monthly_price' => $yearly ? null : $draft['price'],
            'yearly_price' => $yearly ? $draft['price'] : null,
            'workspace_keys' => $draft['workspace_keys'],
            'section_keys' => $draft['section_keys'],
            'feature_details' => $draft['features'],
            'self_serve' => true,
            'is_active' => true,
        ]);

        return [
            'plan' => $plan,
            'summary' => $this->summary($plan),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{name: string, description: ?string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}
     */
    public function normalize(array $parsed): array
    {
        $name = trim((string) ($parsed['name'] ?? ''));
        $price = round((float) ($parsed['price'] ?? $parsed['amount'] ?? 0), 2);
        if ($name === '' || $price <= 0) {
            throw ValidationException::withMessages([
                'description' => ['Add a price in KES to that short description, for example “Small shop, 2000 a month”.'],
            ]);
        }

        $interval = strtolower(trim((string) ($parsed['interval'] ?? 'monthly')));
        $yearly = in_array($interval, ['yearly', 'annual', 'year'], true);

        $features = [];
        foreach (is_array($parsed['features'] ?? null) ? $parsed['features'] : [] as $row) {
            if (is_string($row)) {
                $label = trim($row);
                $detail = null;
            } elseif (is_array($row)) {
                $label = trim((string) ($row['label'] ?? ''));
                $detail = trim((string) ($row['detail'] ?? ''));
                $detail = $detail !== '' ? $detail : null;
            } else {
                continue;
            }
            if ($label === '') {
                continue;
            }
            $features[] = ['label' => $label, 'detail' => $detail];
        }

        $workspaces = [];
        foreach (is_array($parsed['workspace_keys'] ?? null) ? $parsed['workspace_keys'] : [] as $key) {
            $id = strtolower(trim((string) $key));
            if (isset(self::WORKSPACES[$id])) {
                $workspaces[] = $id;
            }
        }

        $allowedSections = array_flip(BackofficeSections::SECTION_KEYS);
        $sections = [];
        foreach (is_array($parsed['section_keys'] ?? null) ? $parsed['section_keys'] : [] as $key) {
            $id = strtolower(trim((string) $key));
            if (isset($allowedSections[$id]) && isset(self::SECTIONS[$id])) {
                $sections[] = $id;
            }
        }

        return [
            'name' => $name,
            'description' => trim((string) ($parsed['description'] ?? '')) ?: null,
            'interval' => $yearly ? 'yearly' : 'monthly',
            'price' => $price,
            'features' => $features,
            'workspace_keys' => array_values(array_unique($workspaces)),
            'section_keys' => array_values(array_unique($sections)),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(PlatformPlan $plan): array
    {
        $yearly = in_array(strtolower((string) $plan->interval), ['annual', 'yearly'], true);
        $features = [];
        foreach (is_array($plan->feature_details) ? $plan->feature_details : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $detail = trim((string) ($row['detail'] ?? ''));
            $features[] = $detail !== '' ? $label.': '.$detail : $label;
        }

        return [
            'name' => $plan->name,
            'description' => $plan->description,
            'interval' => $yearly ? 'Yearly' : 'Monthly',
            'price' => $yearly ? (float) $plan->yearly_price : (float) $plan->monthly_price,
            'currency' => $plan->currency ?: 'KES',
            'features' => $features,
            'workspaces' => array_values(array_filter(array_map(
                fn ($key) => self::WORKSPACES[(string) $key] ?? null,
                $plan->workspace_keys ?? [],
            ))),
            'sections' => array_values(array_filter(array_map(
                fn ($key) => self::SECTIONS[(string) $key] ?? null,
                $plan->section_keys ?? [],
            ))),
        ];
    }
}
