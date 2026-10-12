<?php

namespace App\Services\Platform;

use App\Exceptions\Ai\AiProviderException;
use App\Models\PlatformPlan;
use App\Services\Ai\AiProviderFactory;
use App\Services\Ai\AiSettingsResolver;
use App\Services\Erp\BackofficeSections;
use Illuminate\Support\Facades\Log;
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
        'spa' => 'SPA & Kinyozi',
        'driving' => 'Driving School',
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

    public const MIN_POINTS = 8;

    public const MAX_POINTS = 20;

    public const MIN_BRIEF_CHARS = 80;

    public function __construct(protected AiProviderFactory $providers) {}

    /**
     * Centrix already knows its own applications. The administrator writes a full brief and chooses how many card points to create.
     */
    public function systemPrompt(int $points = self::MIN_POINTS): string
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

        $points = max(self::MIN_POINTS, $points);

        return 'You create one Centrix ERP self-serve subscription plan from a full description. A short line is not enough. '
            .'Centrix is a Kenya business system sold per organisation. Retail, wholesale, and distribution share one set of applications. Hotel is a separate industry and must not be mixed with retail unless the description asks for both. SPA & Kinyozi is its own industry and must not be mixed with retail or hotel. Driving School is its own industry and must not be mixed with retail, hotel, or a salon. '
            .'Create exactly '.$points.' feature points. Each point is one features item with a short label and one detail sentence. Do not return fewer than '.$points.' points and do not return more. '
            .'Use the price they wrote, in KES. If they did not write a price, set price to 0. interval is yearly only when they say year, yearly, or annual; otherwise monthly. '
            .'External POS (pos) is the shop till and also needs backoffice. Hotel POS (hotel_bar_pos) is the bar, restaurant, and room charge and also needs hospitality_backoffice. Do not give a shop plan Hotel POS, and do not give a hotel plan External POS or retail Backoffice. '
            .'A small shop gets the till, products, sales, customers, inventory, suppliers, and reports. Add distribution only for delivery or wholesale dispatch, accounting only for books or the ledger, and hr only for staff or payroll. Administration (admin) is for users and branches when the description is a normal operating plan. '
            .'Return JSON only, no markdown. Shape: {"name":"","description":"","industry":"commerce|hospitality|spa|driving","interval":"monthly|yearly","price":0,"features":[{"label":"","detail":""}],"workspace_keys":[],"section_keys":[]}. '
            .'industry is commerce for a shop, wholesale, or distribution plan, hospitality for a hotel, spa for a salon or kinyozi, and driving for a driving school. The price is the price written for that industry only. '
            .'workspace_keys must be chosen from: '.implode(' ', $apps).' '
            .'section_keys must be chosen from: '.implode(', ', $sections).'. '
            .'features must contain exactly '.$points.' points the organisation will read on the plan card.';
    }

    /**
     * The administrator picks the industry and how many card points to create.
     */
    public function userPrompt(string $words, ?string $industry = null, int $points = self::MIN_POINTS): string
    {
        $points = max(self::MIN_POINTS, $points);
        $lead = 'Create exactly '.$points.' feature points. ';
        if (! in_array($industry, ['commerce', 'hospitality', 'spa', 'driving'], true)) {
            return $lead.$words;
        }

        return $lead.'The industry is already chosen: '.$industry.'. Keep that industry. Do not switch it because of words in the description. '.$words;
    }

    /**
     * @return array{plan: PlatformPlan, summary: array<string, mixed>}
     */
    public function createFromWords(string $words, ?string $industry = null, int $points = self::MIN_POINTS): array
    {
        $points = $this->normalizePointCount($points);
        $this->guardBriefLength($words);
        $draft = $this->draftFromBrief($words, $industry, $points);
        if ($draft === null) {
            $draft = $this->draftWithModel($words, $industry, $points);
        }
        $plan = PlatformPlan::query()->create($this->attributesFromDraft($draft) + ['is_active' => true]);

        return [
            'plan' => $plan,
            'summary' => $this->summary($plan),
        ];
    }

    /**
     * @return array{plan: PlatformPlan, summary: array<string, mixed>}
     */
    public function revisePlan(PlatformPlan $plan, string $instruction, ?string $industry = null, int $points = self::MIN_POINTS): array
    {
        $points = $this->normalizePointCount($points);
        $instruction = trim($instruction);
        if (mb_strlen($instruction) < 8) {
            throw ValidationException::withMessages([
                'description' => ['Say what should change, or edit the plan text.'],
            ]);
        }

        $chosen = in_array($industry, ['commerce', 'hospitality', 'spa', 'driving'], true)
            ? $industry
            : ($plan->industry ?: 'commerce');
        $draft = $this->draftFromBrief($instruction, $chosen, $points);
        if ($draft === null) {
            $draft = $this->draftWithModel(
                "Current plan:\n".$this->briefFromPlan($plan)."\n\nChange:\n".$instruction,
                $chosen,
                $points,
            );
        }

        $plan->update($this->attributesFromDraft($draft));
        $plan = $plan->fresh() ?? $plan;

        return [
            'plan' => $plan,
            'summary' => $this->summary($plan),
        ];
    }

    public function briefFromPlan(PlatformPlan $plan): string
    {
        $yearly = in_array(strtolower((string) $plan->interval), ['annual', 'yearly'], true);
        $amount = (float) ($yearly ? ($plan->yearly_price ?? $plan->price) : ($plan->monthly_price ?? $plan->price));
        $price = rtrim(rtrim(number_format($amount, 2, '.', ''), '0'), '.');
        $lines = [];
        $label = (string) (config('erp_industries.definitions.'.($plan->industry ?: 'commerce').'.label') ?? '');
        if ($label !== '') {
            $lines[] = $label;
        }
        $lines[] = trim((string) $plan->name);
        $description = trim((string) $plan->description);
        if ($description !== '') {
            $lines[] = $description;
        }
        $lines[] = '';
        foreach (is_array($plan->feature_details) ? $plan->feature_details : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $feature = trim((string) ($row['label'] ?? ''));
            if ($feature === '') {
                continue;
            }
            $detail = trim((string) ($row['detail'] ?? ''));
            $lines[] = $detail !== '' ? $feature.' — '.$detail : $feature;
        }
        $lines[] = '';
        $lines[] = 'at '.$price.' Ksh'.($yearly ? ' a year' : '');

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}  $draft
     * @return array<string, mixed>
     */
    protected function attributesFromDraft(array $draft): array
    {
        $yearly = $draft['interval'] === 'yearly';

        return [
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
            'industry' => $draft['industry'],
            'workspace_keys' => $draft['workspace_keys'],
            'section_keys' => $draft['section_keys'],
            'feature_details' => $draft['features'],
            'self_serve' => true,
        ];
    }

    /**
     * A written brief already names the plan, the card lines, and the price.
     * Applications are chosen from those lines, so a model reply is not required.
     *
     * @return array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}|null
     */
    public function draftFromBrief(string $words, ?string $industry = null, int $points = self::MIN_POINTS): ?array
    {
        $points = max(self::MIN_POINTS, $points);
        $brief = $this->readBrief($words, $industry);
        if ($brief['name'] === '' || $brief['price'] <= 0 || count($brief['features']) < $points) {
            return null;
        }

        $chosen = $brief['industry'] ?? $industry ?? 'commerce';

        $draft = $this->normalize([
            'name' => $brief['name'],
            'description' => $brief['description'],
            'industry' => $chosen,
            'interval' => $brief['interval'],
            'price' => $brief['price'],
            'features' => $brief['features'],
            'workspace_keys' => $this->workspacesFor($chosen, $words),
            'section_keys' => $this->sectionsFor($chosen, $words),
        ], $industry ?? $chosen);
        $draft['features'] = array_slice($draft['features'], 0, $points);

        return $draft;
    }

    public function normalizePointCount(int $points): int
    {
        if ($points < self::MIN_POINTS) {
            throw ValidationException::withMessages([
                'points' => ['Card points start at '.self::MIN_POINTS.'. Tell Centrix AI how many points to create.'],
            ]);
        }
        if ($points > self::MAX_POINTS) {
            throw ValidationException::withMessages([
                'points' => ['Card points cannot be more than '.self::MAX_POINTS.'.'],
            ]);
        }

        return $points;
    }

    public function guardBriefLength(string $words): void
    {
        if (mb_strlen(trim($words)) < self::MIN_BRIEF_CHARS) {
            throw ValidationException::withMessages([
                'description' => ['Write a full description of what this plan includes. A short line is not enough. Then set how many card points to create, at least '.self::MIN_POINTS.'.'],
            ]);
        }
    }

    /**
     * @return array{name: string, description: ?string, industry: ?string, interval: string, price: float, features: list<array{label: string, detail: string}>}
     */
    public function readBrief(string $words, ?string $forcedIndustry = null): array
    {
        $lines = preg_split('/\R/u', trim($words)) ?: [];
        $name = '';
        $descriptionParts = [];
        $features = [];
        $price = 0.0;
        $interval = 'monthly';
        $industry = in_array($forcedIndustry, ['commerce', 'hospitality', 'spa', 'driving'], true)
            ? $forcedIndustry
            : null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $heading = $this->industryIdFromHeading($line);
            if ($heading !== null && ($industry === null || $heading === $industry)) {
                $industry ??= $heading;
                continue;
            }

            $priced = $this->priceFromLine($line);
            if ($priced !== null) {
                $price = $priced['price'];
                $interval = $priced['interval'];
                continue;
            }

            $feature = $this->featureFromLine($line);
            if ($feature !== null) {
                $features[] = $feature;
                continue;
            }

            if ($name === '') {
                $name = $line;
                continue;
            }

            $descriptionParts[] = $line;
        }

        if ($price <= 0) {
            $priced = $this->priceFromLine($words);
            if ($priced !== null) {
                $price = $priced['price'];
                $interval = $priced['interval'];
            }
        }

        return [
            'name' => $name,
            'description' => $descriptionParts === [] ? null : implode(' ', $descriptionParts),
            'industry' => $industry,
            'interval' => $interval,
            'price' => $price,
            'features' => $features,
        ];
    }

    /**
     * @return array{price: float, interval: string}|null
     */
    public function priceFromLine(string $line): ?array
    {
        $interval = preg_match('/\b(year|yearly|annual)\b/iu', $line) ? 'yearly' : 'monthly';
        $amount = null;
        if (preg_match('/(?:kes|ksh|kshs)\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $line, $match)) {
            $amount = $match[1];
        } elseif (preg_match('/([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*(?:kes|ksh|kshs|\/-)/iu', $line, $match)) {
            $amount = $match[1];
        } elseif (mb_strlen($line) <= 48 && preg_match('/^\s*(?:at|for|price)\s+([0-9][0-9,]*(?:\.[0-9]{1,2})?)\b/iu', $line, $match)) {
            $amount = $match[1];
        }
        if ($amount === null) {
            return null;
        }
        $price = round((float) str_replace(',', '', $amount), 2);
        if ($price <= 0) {
            return null;
        }

        return ['price' => $price, 'interval' => $interval];
    }

    /**
     * @return array{label: string, detail: string}|null
     */
    public function featureFromLine(string $line): ?array
    {
        if (! preg_match('/^(.{2,90}?)\s*(?:—|–|:)\s+(\S.*)$/u', $line, $match)
            && ! preg_match('/^(.{2,90}?)\s+-\s+(\S.*)$/u', $line, $match)) {
            return null;
        }
        $label = trim($match[1]);
        $detail = trim($match[2]);
        if ($label === '' || $detail === '') {
            return null;
        }

        return ['label' => $label, 'detail' => $detail];
    }

    public function industryIdFromHeading(string $line): ?string
    {
        $norm = $this->normalizeHeading($line);
        if ($norm === '') {
            return null;
        }
        foreach (config('erp_industries.definitions', []) as $id => $definition) {
            $label = $this->normalizeHeading((string) ($definition['label'] ?? ''));
            if ($label !== '' && $label === $norm) {
                return (string) $id;
            }
            if ($norm === $this->normalizeHeading((string) $id)) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function workspacesFor(string $industry, string $text): array
    {
        $hay = mb_strtolower($text);
        if ($industry === 'spa') {
            return ['spa', 'admin'];
        }
        if ($industry === 'driving') {
            return ['driving', 'admin'];
        }
        if ($industry === 'hospitality') {
            return ['hotel_bar_pos', 'hospitality_backoffice', 'admin'];
        }

        $keys = ['pos', 'backoffice', 'admin'];
        if (preg_match('/\b(deliver|wholesale|dispatch|distribution)\b/u', $hay)) {
            $keys[] = 'distribution';
        }
        if (preg_match('/\b(account|ledger|books)\b/u', $hay)) {
            $keys[] = 'accounting';
        }
        if (preg_match('/\b(staff|payroll)\b/u', $hay)) {
            $keys[] = 'hr';
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function sectionsFor(string $industry, string $text): array
    {
        if ($industry !== 'commerce') {
            return [];
        }
        $hay = mb_strtolower($text);
        $sections = ['dashboard'];
        if (preg_match('/\b(till|counter|product|price|tax|receipt)\b/u', $hay)) {
            array_push($sections, 'products', 'pos', 'pricing_tax');
        }
        if (preg_match('/\b(stock|inventory|supplier|goods)\b/u', $hay)) {
            array_push($sections, 'inventory', 'suppliers', 'stock_movements');
        }
        if (preg_match('/\b(sales|customer|report|taking)\b/u', $hay)) {
            array_push($sections, 'sales_orders', 'customers', 'reports');
        }

        return array_values(array_unique($sections));
    }

    /**
     * @return array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}
     */
    protected function draftWithModel(string $words, ?string $industry, int $points): array
    {
        $brief = $this->readBrief($words, $industry);
        $parsed = $this->withBriefDefaults($this->askModel($words, $industry, $points), $brief);
        $draft = $this->normalize($parsed, $industry ?? $brief['industry']);
        if (count($draft['features']) < $points) {
            $parsed = $this->withBriefDefaults($this->askModel($words, $industry, $points, count($draft['features'])), $brief);
            $draft = $this->normalize($parsed, $industry ?? $brief['industry']);
        }
        if (count($draft['features']) < $points) {
            throw ValidationException::withMessages([
                'points' => ['Centrix AI returned '.count($draft['features']).' card points. It was asked for '.$points.', and the minimum is '.self::MIN_POINTS.'.'],
            ]);
        }
        $draft['features'] = array_slice($draft['features'], 0, $points);

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array{name: string, description: ?string, industry: ?string, interval: string, price: float, features: list<array{label: string, detail: string}>}  $brief
     * @return array<string, mixed>
     */
    protected function withBriefDefaults(array $parsed, array $brief): array
    {
        if ($brief['name'] !== '' && trim((string) ($parsed['name'] ?? '')) === '') {
            $parsed['name'] = $brief['name'];
        }
        if ($brief['price'] > 0 && (float) ($parsed['price'] ?? 0) <= 0) {
            $parsed['price'] = $brief['price'];
        }
        if (trim((string) ($parsed['interval'] ?? '')) === '') {
            $parsed['interval'] = $brief['interval'];
        }
        if ($brief['description'] && trim((string) ($parsed['description'] ?? '')) === '') {
            $parsed['description'] = $brief['description'];
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>
     */
    protected function askModel(string $words, ?string $industry, int $points, int $already = 0): array
    {
        $runtime = AiSettingsResolver::resolveRuntimeForPlatformTraining();
        if (! $runtime || empty($runtime['api_key'])) {
            throw ValidationException::withMessages([
                'description' => ['No Gemini or OpenAI key is saved for Centrix AI. Add one under Platform → AI training → Credentials.'],
            ]);
        }

        $content = $this->userPrompt($words, $industry, $points);
        if ($already > 0) {
            $content .= ' The previous reply had only '.$already.' feature points. Return JSON again with exactly '.$points.' feature points.';
        }

        try {
            $turn = $this->providers->make($runtime)->chat([
                'system' => $this->systemPrompt($points),
                'messages' => [
                    ['role' => 'user', 'content' => $content],
                ],
                'temperature' => 0.2,
                'max_output_tokens' => 2500,
            ]);
        } catch (AiProviderException $e) {
            throw ValidationException::withMessages([
                'description' => ['Centrix AI could not draft this plan. '.$e->getMessage()],
            ]);
        }

        $parsed = $this->decodePlanJson(trim((string) ($turn['text'] ?? '')));
        if (! is_array($parsed)) {
            throw ValidationException::withMessages([
                'description' => ['Centrix AI did not return a plan. Write a full description, include the price, and set at least '.self::MIN_POINTS.' card points.'],
            ]);
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodePlanJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{[\s\S]*\}/', $raw, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    protected function normalizeHeading(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = str_replace('&', 'and', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return $value;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}
     */
    public function normalize(array $parsed, ?string $forcedIndustry = null): array
    {
        $name = trim((string) ($parsed['name'] ?? ''));
        $price = round((float) ($parsed['price'] ?? $parsed['amount'] ?? 0), 2);
        if ($name === '' || $price <= 0) {
            throw ValidationException::withMessages([
                'description' => ['Include a price in KES in the description, for example “at 2000 Ksh”.'],
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

        $chosen = in_array($forcedIndustry, ['commerce', 'hospitality', 'spa', 'driving'], true)
            ? $forcedIndustry
            : null;
        $industry = $chosen ?? $this->industryFor($workspaces, $name.' '.(string) ($parsed['description'] ?? '').' '.(string) ($parsed['industry'] ?? ''));
        $allowed = array_flip(config('erp_industries.definitions.'.$industry.'.permission_application_ids', []));
        $workspaces = array_values(array_filter(
            array_unique($workspaces),
            fn (string $id) => isset($allowed[$id]),
        ));
        if ($industry !== 'commerce') {
            $sections = [];
        }

        return [
            'name' => $name,
            'description' => trim((string) ($parsed['description'] ?? '')) ?: null,
            'industry' => $industry,
            'interval' => $yearly ? 'yearly' : 'monthly',
            'price' => $price,
            'features' => $features,
            'workspace_keys' => $workspaces,
            'section_keys' => array_values(array_unique($sections)),
        ];
    }

    /**
     * @param  list<string>  $workspaces
     */
    protected function industryFor(array $workspaces, string $text): string
    {
        $asked = strtolower($text);
        $explicit = null;
        foreach (['commerce', 'hospitality', 'spa', 'driving'] as $id) {
            if (preg_match('/\b'.preg_quote($id, '/').'\b/', $asked)) {
                $explicit = $id;
            }
        }
        if ($explicit) {
            return $explicit;
        }
        if (array_intersect($workspaces, ['driving'])) {
            return 'driving';
        }
        if (array_intersect($workspaces, ['spa'])) {
            return 'spa';
        }
        if (array_intersect($workspaces, ['hotel_bar_pos', 'hospitality_backoffice']) || str_contains($asked, 'hotel')) {
            return 'hospitality';
        }
        if (str_contains($asked, 'kinyozi') || str_contains($asked, 'salon')) {
            return 'spa';
        }
        if (str_contains($asked, 'driving school')) {
            return 'driving';
        }

        return 'commerce';
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
            'industry' => $plan->industry ?: 'commerce',
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
