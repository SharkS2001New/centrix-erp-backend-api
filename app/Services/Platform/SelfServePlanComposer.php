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

    public const MIN_BRIEF_CHARS = 3;

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

        return 'You create one Centrix ERP self-serve subscription plan from the description. A short line is enough. '
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
        PlatformPlan::placeAtEnd($plan);

        return [
            'plan' => $plan->fresh(),
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
                'description' => ['Write what this plan includes, and include the price in KES. For example, “Small shop, 2000 a month”.'],
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
        } elseif (preg_match('/\b([0-9][0-9,]{2,}(?:\.[0-9]{1,2})?)\s*(?:a|per)\s+(month|year|yr)\b/iu', $line, $match)) {
            $amount = $match[1];
            if (preg_match('/year|yr/i', $match[2])) {
                $interval = 'yearly';
            }
        } elseif (preg_match('/\b(?:at|for|price)\s+([0-9][0-9,]*(?:\.[0-9]{1,2})?)\b/iu', $line, $match)) {
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
        $sections = ['dashboard', 'products', 'pos', 'pricing_tax', 'sales_orders', 'customers', 'inventory', 'suppliers', 'reports'];
        if (preg_match('/\b(stock|inventory|supplier|goods|movement)\b/u', $hay)) {
            $sections[] = 'stock_movements';
        }
        if (preg_match('/\b(field|route|deliver|dispatch)\b/u', $hay)) {
            $sections[] = 'field_sales';
        }
        if (preg_match('/\b(expense|cost)\b/u', $hay)) {
            $sections[] = 'expenses';
        }

        return array_values(array_unique($sections));
    }

    /**
     * @return array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}
     */
    protected function draftWithModel(string $words, ?string $industry, int $points): array
    {
        return $this->planDraft($words, $industry, $points, $this->askModel($words, $industry, $points));
    }

    /**
     * Finish a plan from the written description. A missing or short model reply is filled out here.
     *
     * @param  array<string, mixed>|null  $modelReply
     * @return array{name: string, description: ?string, industry: string, interval: string, price: float, features: list<array{label: string, detail: ?string}>, workspace_keys: list<string>, section_keys: list<string>}
     */
    public function planDraft(string $words, ?string $industry, int $points, ?array $modelReply): array
    {
        $points = max(self::MIN_POINTS, min(self::MAX_POINTS, $points));
        $brief = $this->readBrief($words, $industry);
        $chosen = in_array($industry, ['commerce', 'hospitality', 'spa', 'driving'], true)
            ? $industry
            : ($brief['industry'] ?? 'commerce');
        $parsed = $this->withBriefDefaults(is_array($modelReply) ? $modelReply : [], $brief);
        if (trim((string) ($parsed['name'] ?? '')) === '') {
            $parsed['name'] = $this->nameFromWords($words, $chosen);
        }
        if ((float) ($parsed['price'] ?? 0) <= 0) {
            $parsed['price'] = $brief['price'];
        }
        if (trim((string) ($parsed['interval'] ?? '')) === '') {
            $parsed['interval'] = $brief['interval'];
        }
        if (! is_array($parsed['workspace_keys'] ?? null) || $parsed['workspace_keys'] === []) {
            $parsed['workspace_keys'] = $this->workspacesFor($chosen, $words);
        }
        if ($chosen === 'commerce' && (! is_array($parsed['section_keys'] ?? null) || $parsed['section_keys'] === [])) {
            $parsed['section_keys'] = $this->sectionsFor($chosen, $words);
        }

        $draft = $this->normalize($parsed, $chosen);
        if ($draft['workspace_keys'] === []) {
            $draft['workspace_keys'] = $this->workspacesFor($chosen, $words);
        }
        if ($chosen === 'commerce' && $draft['section_keys'] === []) {
            $draft['section_keys'] = $this->sectionsFor($chosen, $words);
        }
        $draft['features'] = $this->ensureFeatureCount(
            array_merge($brief['features'], $draft['features']),
            $chosen,
            $words,
            $points,
        );

        return $draft;
    }

    public function nameFromWords(string $words, ?string $industry): string
    {
        $brief = $this->readBrief($words, $industry);
        if ($brief['name'] !== '') {
            return $brief['name'];
        }
        $stripped = preg_replace('/\b(?:at|for|price\s+)?[0-9][0-9,]*(?:\.[0-9]{1,2})?\s*(?:kes|ksh|kshs|\/-)?\s*(?:a|per)?\s*(?:month|year|yearly|annual|yr)?\b/iu', ' ', $words) ?? $words;
        $stripped = trim((string) (preg_replace('/\s+/u', ' ', $stripped) ?? $stripped), " \t\n\r,.-");
        if ($stripped !== '') {
            return mb_substr($stripped, 0, 60);
        }

        return match ($industry) {
            'hospitality' => 'Hotel',
            'spa' => 'Salon',
            'driving' => 'Driving school',
            default => 'Shop',
        };
    }

    /**
     * @param  list<array{label: string, detail: ?string}>  $features
     * @return list<array{label: string, detail: ?string}>
     */
    public function ensureFeatureCount(array $features, string $industry, string $words, int $points): array
    {
        $kept = [];
        $seen = [];
        $push = function (string $label, ?string $detail) use (&$kept, &$seen, $points): void {
            $label = trim($label);
            if ($label === '' || count($kept) >= $points) {
                return;
            }
            $key = mb_strtolower($label);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $detail = trim((string) $detail);
            $kept[] = ['label' => $label, 'detail' => $detail !== '' ? $detail : null];
        };

        foreach ($features as $row) {
            $push((string) ($row['label'] ?? ''), $row['detail'] ?? null);
        }
        foreach ($this->pointsFromProse($words) as $row) {
            $push($row['label'], $row['detail']);
        }
        foreach ($this->cardCatalog($industry) as $row) {
            $push($row['label'], $row['detail']);
        }

        return $kept;
    }

    /**
     * @return list<array{label: string, detail: string}>
     */
    public function pointsFromProse(string $words): array
    {
        $chunks = preg_split('/(?<=[.!?])\s+|\R+/u', trim($words)) ?: [];
        $points = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '' || mb_strlen($chunk) < 20) {
                continue;
            }
            if ($this->industryIdFromHeading($chunk) !== null || $this->priceFromLine($chunk) !== null) {
                continue;
            }
            $feature = $this->featureFromLine($chunk);
            if ($feature !== null) {
                $points[] = $feature;
                continue;
            }
            $label = mb_substr($chunk, 0, 42);
            if (mb_strlen($chunk) > 42) {
                $label = rtrim($label, " \t,.-").'…';
            }
            $points[] = ['label' => $label, 'detail' => $chunk];
        }

        return $points;
    }

    /**
     * @return list<array{label: string, detail: string}>
     */
    public function cardCatalog(string $industry): array
    {
        $rows = match ($industry) {
            'hospitality' => [
                ['Bar and kitchen till', 'Take food and drink orders and close the bill.'],
                ['Room charges', 'Post a guest’s bar or restaurant bill to the room.'],
                ['Rooms and stays', 'Check guests in, assign a room, and check them out.'],
                ['Guest bills', 'Room, bar, and restaurant charges on one folio.'],
                ['Daily house report', 'Who is in house and what the property took today.'],
                ['Reservations', 'Hold a room before the guest arrives.'],
                ['Front office', 'Check-in, rooms, and checkout for the stay.'],
                ['Outlets', 'Bar and restaurant sales can charge the room or be paid now.'],
                ['Housekeeping', 'See which rooms are occupied, dirty, or ready.'],
                ['Menu', 'Food and drink items with a price for the outlet.'],
                ['Shift takings', 'See what the outlet sold on that shift.'],
                ['Guest list', 'Who is in house and which room they are in.'],
                ['Checkout', 'Close the folio and take the final payment.'],
                ['Stock and orders', 'Receive goods for the kitchen and the bar.'],
                ['Users and branches', 'Add front office and outlet staff.'],
                ['Expenses', 'Record the costs of running the property.'],
                ['Reports', 'Occupancy, outlet sales, and the day’s takings.'],
                ['Room status', 'See which rooms can be sold tonight.'],
                ['Payments', 'Cash, card, and M-Pesa against a guest bill.'],
                ['Night audit', 'Close the day and carry unpaid charges forward.'],
            ],
            'spa' => [
                ['Chair and walk-in till', 'Sell a cut or treatment without a prior booking.'],
                ['Services and prices', 'Each service has a price, a duration, and who can do it.'],
                ['Today’s takings', 'Cash, M-Pesa, and card for the work done today.'],
                ['Appointments', 'Book a client, a service, and a staff member.'],
                ['Staff commission', 'See what each person earned from the services they did.'],
                ['Client list', 'Phone, visits, and what they usually take.'],
                ['Treatment book', 'Appointments and rooms for longer sessions.'],
                ['Retail on the till', 'Sell products as well as treatments.'],
                ['Product stock', 'See what is left on the shelf and what to reorder.'],
                ['Expenses', 'Record rent, supplies, and other running costs.'],
                ['Staff roster', 'Who is working and which services they can perform.'],
                ['Walk-in queue', 'Add someone who arrived without a booking.'],
                ['Service photo', 'Show the treatment on the list and on the till.'],
                ['Receipts', 'Give the client a receipt for the visit.'],
                ['Packages', 'Sell several sessions as one price.'],
                ['Chairs', 'Assign a barber chair on the booking.'],
                ['Suppliers', 'Record who supplies the products you sell.'],
                ['Reports', 'Services, product sales, and what the salon took in.'],
                ['Users', 'Add the people who book clients and take payment.'],
                ['Client history', 'See past visits and what was paid.'],
            ],
            'driving' => [
                ['Student register', 'Name, phone, course, and package when they join.'],
                ['Courses and prices', 'Licence class, lesson counts, and what the package costs.'],
                ['Fee receipts', 'Record what the student paid and what is still outstanding.'],
                ['Lesson book', 'Book a student, an instructor, a time, and a vehicle.'],
                ['Instructors', 'See who is teaching and avoid double-booking the same hour.'],
                ['Fees and assessments', 'Receipts plus the school’s own practical and theory checks.'],
                ['Vehicles', 'Keep the training cars and mark one unavailable.'],
                ['Attendance', 'Check a student in and out when they come for a lesson.'],
                ['School reports', 'Enrolments, outstanding fees, lessons, and vehicle use.'],
                ['Packages', 'A second price, such as weekend lessons or extra hours.'],
                ['Schedule', 'The day’s lessons arranged by instructor.'],
                ['Outstanding fees', 'See which students still owe for the course.'],
                ['Theory and practical', 'Book either kind of lesson.'],
                ['Instructor pay', 'A calculated rate for each lesson taught.'],
                ['Enrolment', 'Register a student onto a course and a package.'],
                ['Receipts', 'Print the fee receipt after a payment.'],
                ['Assessments', 'Record an internal school check and a score.'],
                ['Users', 'Add the people who book lessons and take fees.'],
                ['Expenses', 'Operating costs, kept separate from collected fees.'],
                ['Student status', 'See who is active and who has finished.'],
            ],
            default => [
                ['Till and products', 'Sell at the counter, with prices, tax, and a receipt.'],
                ['Stock on hand', 'See what is left and receive goods from suppliers.'],
                ['Daily sales', 'Customers, today’s takings, and a simple sales report.'],
                ['Customer accounts', 'Track who still owes and what they have bought.'],
                ['Purchases', 'Record supplier bills and the goods that came in.'],
                ['Pricing and tax', 'Set the selling price and the tax on each product.'],
                ['Receipts', 'Print or share the receipt for a completed sale.'],
                ['Reports', 'See sales, stock, and what the business took in.'],
                ['Users and branches', 'Add the people who sell and the branch they work in.'],
                ['Expenses', 'Record the costs of running the shop.'],
                ['Promotions', 'Offer a discount or a loyalty price on selected goods.'],
                ['Field sales', 'Record orders taken outside the shop.'],
                ['Dispatch', 'Load and send orders out for delivery.'],
                ['After sales', 'Handle a return or a complaint against a sale.'],
                ['Stock movements', 'See what came in, what was sold, and what was adjusted.'],
                ['Debtors', 'Follow shop credit from the sale to the payment.'],
                ['Suppliers', 'Keep the businesses you buy from on one list.'],
                ['End of day', 'Close the till and see the day’s takings.'],
                ['Product list', 'Names, prices, and what is still in stock.'],
                ['Branch sales', 'Compare what each branch sold.'],
            ],
        };

        return array_map(
            fn (array $row) => ['label' => $row[0], 'detail' => $row[1]],
            $rows,
        );
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

        $request = [
            'system' => $this->systemPrompt($points),
            'messages' => [
                ['role' => 'user', 'content' => $content],
            ],
            'temperature' => 0.2,
            'max_output_tokens' => 8192,
            'thinking_level' => 'MINIMAL',
            'json' => true,
        ];

        try {
            $turn = $this->providers->make($runtime)->chat($request);
        } catch (AiProviderException $e) {
            if (! in_array($e->codeKey, ['provider_error'], true) && $e->httpStatus !== 400) {
                throw ValidationException::withMessages([
                    'description' => ['Centrix AI could not draft this plan. '.$e->getMessage()],
                ]);
            }
            $request['json'] = false;
            try {
                $turn = $this->providers->make($runtime)->chat($request);
            } catch (AiProviderException $retry) {
                throw ValidationException::withMessages([
                    'description' => ['Centrix AI could not draft this plan. '.$retry->getMessage()],
                ]);
            }
        }

        $parsed = $this->decodePlanJson(trim((string) ($turn['text'] ?? '')));
        if (! is_array($parsed)) {
            Log::info('Self-serve plan model reply was not JSON. The description will still become a plan.', [
                'excerpt' => mb_substr(trim((string) ($turn['text'] ?? '')), 0, 400),
            ]);

            return [];
        }

        return $parsed;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decodePlanJson(string $raw): ?array
    {
        $raw = trim($raw);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"], ['"', '"', "'", "'"], $raw);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/i', $raw, $fence)) {
            $raw = trim($fence[1]);
        }
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;

        $candidates = [$raw];
        $object = $this->extractJsonObject($raw);
        if (is_string($object) && $object !== '') {
            $candidates[] = $object;
        }

        foreach ($candidates as $candidate) {
            $decoded = $this->decodeJsonObject($candidate);
            if (is_array($decoded)) {
                return $this->coercePlanShape($decoded);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function decodeJsonObject(string $candidate): ?array
    {
        $candidate = preg_replace('/,\s*([}\]])/', '$1', $candidate) ?? $candidate;
        $decoded = json_decode($candidate, true);

        return is_array($decoded) ? $decoded : null;
    }

    protected function extractJsonObject(string $raw): ?string
    {
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }
        $depth = 0;
        $stack = [];
        $in = false;
        $esc = false;
        $length = strlen($raw);
        for ($i = $start; $i < $length; $i++) {
            $char = $raw[$i];
            if ($in) {
                if ($esc) {
                    $esc = false;
                    continue;
                }
                if ($char === '\\') {
                    $esc = true;
                    continue;
                }
                if ($char === '"') {
                    $in = false;
                }
                continue;
            }
            if ($char === '"') {
                $in = true;
                continue;
            }
            if ($char === '{' || $char === '[') {
                $depth++;
                $stack[] = $char;
                continue;
            }
            if ($char === '}' || $char === ']') {
                $depth--;
                array_pop($stack);
                if ($depth === 0) {
                    return substr($raw, $start, $i - $start + 1);
                }
            }
        }

        $partial = substr($raw, $start);
        if ($in) {
            $partial .= '"';
        }
        while ($stack !== []) {
            $open = array_pop($stack);
            $partial .= $open === '[' ? ']' : '}';
        }

        return $partial;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    protected function coercePlanShape(array $decoded): array
    {
        if (! isset($decoded['features']) || ! is_array($decoded['features'])) {
            foreach (['feature_details', 'card_points', 'points'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {
                    $decoded['features'] = $decoded[$key];
                    break;
                }
            }
        }
        if (isset($decoded['features']) && is_array($decoded['features'])) {
            $decoded['features'] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                if (trim((string) ($row['label'] ?? '')) === '') {
                    $row['label'] = $row['title'] ?? $row['name'] ?? $row['point'] ?? '';
                }
                if (trim((string) ($row['detail'] ?? '')) === '') {
                    $row['detail'] = $row['description'] ?? $row['text'] ?? '';
                }

                return $row;
            }, $decoded['features']);
        }

        return $decoded;
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
