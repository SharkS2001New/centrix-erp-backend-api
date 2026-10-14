<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformOneTimeQuote;
use App\Models\User;
use App\Services\Erp\IndustryRegistry;
use App\Services\Notifications\AdminNotificationService;
use Illuminate\Validation\ValidationException;
use App\Models\PlatformBillingSetting;
use App\Models\PlatformLicenceBalance;
use App\Models\PlatformLicenceCheckout;
use App\Models\PlatformLicencePayment;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Centrix SaaS Paybill. Shop paybills stay on sale matching.
 * The account number is the organisation code or the admin phone.
 * The amount picks monthly, yearly, or a prepaid day pack.
 */
class PlatformPaybillLicenceService
{
    public function settings(): ?PlatformBillingSetting
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_billing_settings')) {
            return null;
        }

        return PlatformBillingSetting::current();
    }

    /** @param  array{short_code?: string|null, account_name_prefix?: string|null}  $data */
    public function saveSettings(array $data): PlatformBillingSetting
    {
        $platformCode = (string) config('erp.platform_company_code', 'PLATFORM');
        $platform = Organization::query()
            ->whereRaw('UPPER(company_code) = ?', [strtoupper($platformCode)])
            ->first();

        $row = $this->settings() ?? new PlatformBillingSetting();
        $fill = [
            'organization_id' => $platform?->id,
        ];
        if (array_key_exists('short_code', $data)) {
            $fill['short_code'] = $this->blankToNull($data['short_code'] ?? null);
        }
        if (array_key_exists('account_name_prefix', $data)) {
            $fill['account_name_prefix'] = $this->blankToNull($data['account_name_prefix'] ?? null);
        }
        if (array_key_exists('duration_discount_percent', $data)) {
            $fill['duration_discount_percent'] = min(100, max(0, round((float) $data['duration_discount_percent'], 2)));
        }
        if (array_key_exists('duration_discount_min_months', $data)) {
            $fill['duration_discount_min_months'] = min(36, max(0, (int) $data['duration_discount_min_months']));
        }
        $row->fill($fill);
        $row->save();

        return $row->fresh();
    }

    public function handlesPayload(array $payload): bool
    {
        $configured = trim((string) ($this->settings()?->short_code ?? ''));
        if ($configured === '') {
            return false;
        }

        $shortCode = trim((string) (
            $payload['BusinessShortCode']
            ?? $payload['business_short_code']
            ?? ''
        ));

        return $shortCode !== '' && $shortCode === $configured;
    }

    public function acceptC2b(array $payload): ?PlatformLicencePayment
    {
        $transactionId = trim((string) ($payload['TransID'] ?? $payload['trans_id'] ?? ''));
        $amount = round((float) ($payload['TransAmount'] ?? $payload['trans_amount'] ?? 0), 2);
        $phone = trim((string) ($payload['MSISDN'] ?? $payload['msisdn'] ?? $payload['PhoneNumber'] ?? ''));
        $billRef = trim((string) ($payload['BillRefNumber'] ?? $payload['bill_ref_number'] ?? ''));
        $shortCode = trim((string) ($payload['BusinessShortCode'] ?? $payload['business_short_code'] ?? ''));

        if ($transactionId === '' || $amount < 1) {
            return null;
        }

        return DB::transaction(function () use ($transactionId, $amount, $phone, $billRef, $shortCode) {
            $existing = PlatformLicencePayment::query()
                ->where('mpesa_transaction_id', $transactionId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $reference = $this->accountReference($billRef);
            $resolved = $this->resolveOrganization($reference);

            $payment = PlatformLicencePayment::query()->create([
                'organization_id' => $resolved['organization']?->id,
                'mpesa_transaction_id' => $transactionId,
                'phone_number' => $phone !== '' ? $phone : null,
                'amount' => $amount,
                'bill_ref_number' => $billRef !== '' ? $billRef : null,
                'business_short_code' => $shortCode !== '' ? $shortCode : null,
                'account_reference' => $reference !== '' ? $reference : null,
                'status' => 'unmatched',
                'match_method' => $resolved['method'],
                'note' => $resolved['note'],
            ]);

            if (! $resolved['organization']) {
                return $payment;
            }

            return $this->applyCredit($payment, $resolved['organization'], $amount);
        });
    }

    /**
     * Paybill instructions shown on the licence warning and expired screens.
     *
     * @return array<string, mixed>|null
     */
    public function instructionsFor(Organization $org, ?PlatformSubscription $subscription): ?array
    {
        $shortCode = trim((string) ($this->settings()?->short_code ?? ''));
        if ($shortCode === '') {
            return null;
        }

        $plan = $subscription?->plan;
        $catalogue = $plan ? $this->priceCatalogue($plan) : [];
        $byProduct = [];
        foreach ($catalogue as $row) {
            $byProduct[$row['product']] = $row;
        }

        return [
            'short_code' => $shortCode,
            'account_number' => $org->company_code,
            'account_phone' => $org->primary_tel,
            'currency' => $plan?->currency ?? $subscription?->currency ?? 'KES',
            'monthly_price' => isset($byProduct['monthly']) ? $byProduct['monthly']['price'] : null,
            'yearly_price' => isset($byProduct['yearly']) ? $byProduct['yearly']['price'] : null,
            'token_pack_price' => isset($byProduct['token']) ? $byProduct['token']['price'] : null,
            'token_pack_days' => isset($byProduct['token']) ? $byProduct['token']['days'] : null,
        ];
    }

    /**
     * Monthly, yearly, and token choices shown on the self-pay screen.
     *
     * @return list<array<string, mixed>>
     */
    public function paymentOffers(Organization $org, ?PlatformSubscription $subscription): array
    {
        $plan = $subscription?->plan;
        $catalogue = $plan ? $this->priceCatalogue($plan) : [];
        $byProduct = [];
        foreach ($catalogue as $row) {
            $byProduct[$row['product']] = $row;
        }
        $paybill = $this->instructionsFor($org, $subscription) ?? [];
        $currency = $paybill['currency'] ?? $plan?->currency ?? $subscription?->currency ?? 'KES';
        $paybill += [
            'monthly_price' => $byProduct['monthly']['price'] ?? null,
            'yearly_price' => $byProduct['yearly']['price'] ?? null,
            'token_pack_price' => $byProduct['token']['price'] ?? null,
            'token_pack_days' => $byProduct['token']['days'] ?? null,
            'currency' => $currency,
        ];
        $unlocked = [
            'Sales, tills, and invoices',
            'Stock, purchases, and supplier bills',
            'Customers, routes, and statements',
            'Payroll, attendance, and accounting',
            'Every module already included on your Centrix plan',
        ];

        $offers = [[
            'id' => 'monthly',
            'title' => 'Monthly subscription',
            'price' => $paybill['monthly_price'] ?? null,
            'currency' => $currency,
            'period' => '30 days',
            'summary' => 'Pay once and use the whole of Centrix for 30 days. Pay the same amount again to renew.',
            'features' => array_merge(['30 days of full Centrix from the day the payment is confirmed'], $unlocked),
        ], [
            'id' => 'yearly',
            'title' => 'Yearly subscription',
            'price' => $paybill['yearly_price'] ?? null,
            'currency' => $currency,
            'period' => '1 year',
            'summary' => 'One M-Pesa payment keeps Centrix open for a calendar year.',
            'features' => array_merge(['1 year of full Centrix from the day the payment is confirmed'], $unlocked),
        ]];

        $example = $paybill['token_pack_price'] ?? null;
        if (app(BillingPolicy::class)->tokensEnabled()) {
        $offers[] = [
            'id' => 'token',
            'title' => 'Token subscription',
            'price' => $example,
            'currency' => $currency,
            'period' => '1 KES = 1 token',
            'summary' => 'The shillings you pay become tokens. Paying 500 KES gives the organisation 500 tokens. Any other amount works the same way.',
            'features' => array_merge(
                [
                    'Each completed sale uses the published token cost when that order is saved online',
                    'An offline sale waits until sync uploads it, and a repeat upload of the same order is not charged again',
                    'Reading pages and reports uses 0 tokens',
                    'When the balance reaches 0, only products, customers, suppliers, and sales reports stay open',
                ],
                $unlocked,
            ),
        ];
        }

        return $offers;
    }

    /**
     * @return list<array{product: string, price: float, days: int}>
     */
    public function priceCatalogue(PlatformPlan $plan): array
    {
        $interval = strtolower(trim((string) $plan->interval));
        $legacy = $plan->renewal_price ?? $plan->price;
        $yearlyInterval = in_array($interval, ['annual', 'yearly', 'year'], true);

        $monthly = $plan->monthly_price;
        if (($monthly === null || (float) $monthly <= 0) && ! $yearlyInterval && $legacy !== null) {
            $monthly = $legacy;
        }

        $yearly = $plan->yearly_price;
        if (($yearly === null || (float) $yearly <= 0) && $yearlyInterval && $legacy !== null) {
            $yearly = $legacy;
        }

        $rows = [];
        if ($monthly !== null && (float) $monthly > 0) {
            $rows[] = ['product' => 'monthly', 'price' => round((float) $monthly, 2), 'days' => 30];
        }
        if ($yearly !== null && (float) $yearly > 0) {
            $rows[] = ['product' => 'yearly', 'price' => round((float) $yearly, 2), 'days' => 365];
        }
        $tokenPrice = $plan->token_pack_price;
        $tokenDays = (int) ($plan->token_pack_days ?? 0);
        if (app(BillingPolicy::class)->tokensEnabled() && $tokenPrice !== null && (float) $tokenPrice > 0 && $tokenDays > 0) {
            $rows[] = ['product' => 'token', 'price' => round((float) $tokenPrice, 2), 'days' => $tokenDays];
        }

        usort($rows, function (array $a, array $b): int {
            $price = $b['price'] <=> $a['price'];
            if ($price !== 0) {
                return $price;
            }
            $rank = ['yearly' => 3, 'monthly' => 2, 'token' => 1];

            return ($rank[$b['product']] ?? 0) <=> ($rank[$a['product']] ?? 0);
        });

        return $rows;
    }

    public function accountReference(string $billRef): string
    {
        $clean = strtoupper(preg_replace('/\s+/', '', trim($billRef)) ?? '');
        if ($clean === '') {
            return '';
        }

        $prefix = strtoupper(preg_replace('/\s+/', '', trim((string) ($this->settings()?->account_name_prefix ?? ''))) ?? '');
        if ($prefix === '' || strlen($prefix) < 2 || ! str_starts_with($clean, $prefix) || $clean === $prefix) {
            return $clean;
        }

        return ltrim(substr($clean, strlen($prefix)), "-_./");
    }

    /**
     * @return array{organization: ?Organization, method: ?string, note: ?string}
     */
    public function resolveOrganization(string $reference): array
    {
        if ($reference === '') {
            return ['organization' => null, 'method' => null, 'note' => 'missing_account'];
        }

        $byCode = Organization::findByCompanyCodeIdentifier($reference);
        if ($byCode && ! $this->isPlatform($byCode)) {
            return ['organization' => $byCode, 'method' => 'org_code', 'note' => null];
        }

        $normalized = PhoneNumber::normalize($reference);
        if ($normalized === null || strlen($normalized) < 9) {
            return ['organization' => null, 'method' => null, 'note' => 'unknown_account'];
        }

        $tail = ltrim($normalized, '0');
        $variants = array_values(array_unique(array_filter([
            $normalized,
            $tail,
            '254'.$tail,
            '+254'.$tail,
        ])));
        $candidates = Organization::query()
            ->whereNotNull('primary_tel')
            ->where('primary_tel', '!=', '')
            ->where(function ($query) use ($variants, $tail) {
                foreach ($variants as $variant) {
                    $query->orWhere('primary_tel', $variant);
                }
                $query->orWhere('primary_tel', 'like', '%'.$tail);
            })
            ->limit(30)
            ->get()
            ->filter(function (Organization $org) use ($normalized) {
                return ! $this->isPlatform($org)
                    && PhoneNumber::normalize($org->primary_tel) === $normalized;
            })
            ->values();

        if ($candidates->count() === 1) {
            return ['organization' => $candidates->first(), 'method' => 'admin_phone', 'note' => null];
        }

        if ($candidates->count() > 1) {
            return ['organization' => null, 'method' => null, 'note' => 'ambiguous_phone'];
        }

        return ['organization' => null, 'method' => null, 'note' => 'unknown_account'];
    }

    protected function applyCredit(PlatformLicencePayment $payment, Organization $org, float $amount): PlatformLicencePayment
    {
        $balanceRow = PlatformLicenceBalance::query()->firstOrCreate(
            ['organization_id' => $org->id],
            ['balance' => 0],
        );
        $balanceRow = PlatformLicenceBalance::query()
            ->whereKey($balanceRow->id)
            ->lockForUpdate()
            ->first();

        $quoted = $this->applyPendingCheckout($payment, $org, $amount);
        if ($quoted) {
            return $quoted;
        }

        $balance = round((float) $balanceRow->balance + $amount, 2);
        $subscription = PlatformSubscription::query()
            ->with('plan')
            ->where('organization_id', $org->id)
            ->lockForUpdate()
            ->first();

        $catalogue = array_values(array_filter(
            $subscription?->plan ? $this->priceCatalogue($subscription->plan) : [],
            fn (array $row) => in_array($row['product'], ['monthly', 'yearly'], true),
        ));

        $periodBefore = ($subscription->current_period_end ?? $subscription->trial_ends_at)?->toDateString();
        $appliedAmount = 0.0;
        $daysAdded = 0;
        $products = [];
        $guard = 0;

        while ($guard < 36) {
            $guard++;
            $product = null;
            foreach ($catalogue as $row) {
                if ($balance + 0.001 >= $row['price']) {
                    $product = $row;
                    break;
                }
            }
            if (! $product) {
                break;
            }

            $balance = round($balance - $product['price'], 2);
            $appliedAmount = round($appliedAmount + $product['price'], 2);
            $daysAdded += $product['product'] === 'yearly' ? 365 : (int) $product['days'];
            $products[] = $product['product'];
            $this->extendSubscription($subscription, $product);
        }

        $balanceRow->balance = $balance;
        $balanceRow->save();

        $payment->fill([
            'status' => $products !== [] ? 'applied' : 'unmatched',
            'product' => $products[0] ?? null,
            'days_added' => $products !== [] ? $daysAdded : null,
            'amount_applied' => $products !== [] ? $appliedAmount : null,
            'balance_after' => $balance,
            'period_end_before' => $periodBefore,
            'period_end_after' => $products !== []
                ? $subscription->current_period_end?->toDateString()
                : null,
            'note' => $products === [] ? null : (count($products) > 1 ? 'stacked' : null),
        ])->save();

        if ($products === []) {
            return $this->creditTokensFromBalance($payment, $balanceRow, $balance);
        }

        return $payment->fresh();
    }

    protected function creditTokensFromBalance(PlatformLicencePayment $payment, PlatformLicenceBalance $balanceRow, float $moneyBalance): PlatformLicencePayment
    {
        if (! app(BillingPolicy::class)->tokensEnabled()) {
            $payment->fill([
                'status' => 'unmatched',
                'product' => null,
                'note' => 'tokens_disabled',
                'balance_after' => $moneyBalance,
            ])->save();

            return $payment->fresh();
        }

        $tokens = max(0, (int) round($moneyBalance));
        $beforeTokens = (int) $balanceRow->token_balance;
        $balanceRow->token_balance = $beforeTokens + $tokens;
        $balanceRow->balance = 0;
        $balanceRow->save();
        app(TokenLowBalanceNotifier::class)->afterChange((int) $balanceRow->organization_id, $beforeTokens, $beforeTokens + $tokens);

        $payment->fill([
            'status' => 'applied',
            'product' => 'token',
            'days_added' => null,
            'amount_applied' => $tokens,
            'balance_after' => 0,
            'note' => 'tokens',
        ])->save();

        return $payment->fresh();
    }

    /** @return array{percent: float, min_months: int} */
    public function durationDiscount(): array
    {
        $settings = $this->settings();

        return [
            'percent' => (float) ($settings?->duration_discount_percent ?? 0),
            'min_months' => (int) ($settings?->duration_discount_min_months ?? 3),
        ];
    }

    /**
     * Active self-serve plans an organisation can choose on the subscription screen.
     *
     * @return list<array<string, mixed>>
     */
    public function monthlyPlanChoices(Organization $org): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_plans')) {
            return [];
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('platform_plans', 'self_serve')) {
            return [];
        }

        $discount = $this->durationDiscount();
        $industry = IndustryRegistry::industryForProfile((string) $org->deployment_profile);

        return PlatformPlan::query()
            ->where('is_active', true)
            ->where('self_serve', true)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('platform_plans', 'industry'),
                function ($query) use ($industry) {
                    $query->where(function ($inner) use ($industry) {
                        $inner->where('industry', $industry);
                        if ($industry === 'commerce') {
                            $inner->orWhereNull('industry')->orWhere('industry', '');
                        }
                    });
                },
            )
            ->where(function ($query) {
                $query->where('monthly_price', '>', 0)->orWhere('yearly_price', '>', 0);
            })
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('platform_plans', 'sort_order'),
                fn ($query) => $query->orderBy('sort_order'),
            )
            ->orderBy('name')
            ->get()
            ->map(function (PlatformPlan $plan) use ($discount) {
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
                    'id' => (int) $plan->id,
                    'name' => $plan->name,
                    'description' => $plan->description,
                    'interval' => in_array(strtolower((string) $plan->interval), ['annual', 'yearly'], true) ? 'yearly' : 'monthly',
                    'currency' => $plan->currency ?: 'KES',
                    'monthly_price' => $plan->monthly_price !== null ? round((float) $plan->monthly_price, 2) : null,
                    'yearly_price' => $plan->yearly_price !== null ? round((float) $plan->yearly_price, 2) : null,
                    'features' => $features,
                    'section_keys' => array_values($plan->section_keys ?? []),
                    'industry' => $plan->industry ?: 'commerce',
                    'workspace_keys' => array_values($plan->workspace_keys ?? []),
                    'discount_percent' => $discount['percent'],
                    'discount_min_months' => $discount['min_months'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{amount: float, discount: float, discount_percent: float, months: int, days: int, unit_price: float, gross: float}
     */
    public static function quoteMonths(float $unitPrice, int $months, int $minMonths, float $percent): array
    {
        $months = max(1, min(36, $months));
        $gross = round($unitPrice * $months, 2);
        $appliedPercent = ($months > $minMonths && $percent > 0) ? min(100, $percent) : 0.0;
        $discount = round($gross * $appliedPercent / 100, 2);

        return [
            'months' => $months,
            'unit_price' => round($unitPrice, 2),
            'gross' => $gross,
            'discount' => $discount,
            'discount_percent' => $appliedPercent,
            'amount' => round($gross - $discount, 2),
            'days' => $months * 30,
        ];
    }

    protected function planMatchesIndustry(Organization $org, PlatformPlan $plan): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('platform_plans', 'industry')) {
            return true;
        }

        $orgIndustry = IndustryRegistry::industryForProfile((string) $org->deployment_profile);
        $planIndustry = trim((string) ($plan->industry ?: 'commerce'));

        return $planIndustry === $orgIndustry;
    }

    protected function assertPlanMatchesIndustry(Organization $org, PlatformPlan $plan): void
    {
        if ($this->planMatchesIndustry($org, $plan)) {
            return;
        }

        throw ValidationException::withMessages([
            'plan_id' => ['That subscription package belongs to a different industry.'],
        ]);
    }

    public function startCheckout(Organization $org, PlatformPlan $plan, int $months, ?string $phone = null, ?string $billing = null): PlatformLicenceCheckout
    {
        $this->assertPlanMatchesIndustry($org, $plan);

        if ($billing === 'yearly' && (float) ($plan->yearly_price ?? 0) > 0) {
            return PlatformLicenceCheckout::query()->create([
                'organization_id' => $org->id,
                'plan_id' => $plan->id,
                'months' => 12,
                'amount' => round((float) $plan->yearly_price, 2),
                'discount_percent' => 0,
                'phone_number' => $phone,
                'status' => 'pending',
            ]);
        }

        $discount = $this->durationDiscount();
        $quote = self::quoteMonths(
            (float) $plan->monthly_price,
            $months,
            $discount['min_months'],
            $discount['percent'],
        );

        return PlatformLicenceCheckout::query()->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'months' => $quote['months'],
            'amount' => $quote['amount'],
            'discount_percent' => $quote['discount_percent'],
            'phone_number' => $phone,
            'status' => 'pending',
        ]);
    }

    /** Renew a yearly organisation at the amount saved on that organisation. */
    public function startRenewalCheckout(Organization $org, ?string $phone = null): PlatformLicenceCheckout
    {
        $subscription = PlatformSubscription::query()
            ->with('plan')
            ->where('organization_id', $org->id)
            ->first();
        $licenses = app(OrganizationLicenseService::class);
        if (! $subscription || $licenses->billingCycle($subscription) !== 'yearly') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'billing' => ['This organisation is not on a yearly plan.'],
            ]);
        }

        $amount = $licenses->renewalAmount($subscription);
        if ($amount === null || $amount <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'amount' => ['A platform administrator has not set the renew amount for this organisation.'],
            ]);
        }

        return PlatformLicenceCheckout::query()->create([
            'organization_id' => $org->id,
            'plan_id' => $subscription->plan_id,
            'months' => 12,
            'amount' => $amount,
            'discount_percent' => 0,
            'phone_number' => $phone,
            'status' => 'pending',
        ]);
    }

    /**
     * @return array{quote: PlatformOneTimeQuote, emailed: bool, already: bool}
     */
    public function requestOneTimeQuote(Organization $org, ?User $user): array
    {
        $recent = PlatformOneTimeQuote::query()
            ->where('organization_id', $org->id)
            ->where('status', 'requested')
            ->where('created_at', '>=', now()->subHour())
            ->latest('id')
            ->first();
        if ($recent) {
            return ['quote' => $recent, 'emailed' => false, 'already' => true];
        }

        $quote = PlatformOneTimeQuote::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user?->id,
            'status' => 'requested',
        ]);

        $emailedTo = $this->emailOneTimeQuote($org, $user, $quote);
        if ($emailedTo !== []) {
            $quote->update(['emailed_to' => $emailedTo]);
        }
        $this->notifyOneTimeQuote($org, $user);

        return ['quote' => $quote->fresh(), 'emailed' => $emailedTo !== [], 'already' => false];
    }

    /**
     * @return list<string>
     */
    protected function emailOneTimeQuote(Organization $org, ?User $user, PlatformOneTimeQuote $quote): array
    {
        $recipients = User::query()
            ->where('is_super_admin', true)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->pluck('email')
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->push(strtolower(trim((string) config('erp.platform_super_admin_email'))))
            ->push(strtolower(trim((string) config('backup.notify_email'))))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            return [];
        }

        $who = trim((string) ($user?->full_name ?: $user?->email ?: 'An organisation user'));
        $subject = 'Centrix one-time payment quote — '.$org->company_code;
        $body = implode("\n", [
            $org->org_name.' ('.$org->company_code.') asked to buy Centrix as a one-time payment.',
            'Requested by '.$who.($user?->email ? ' <'.$user->email.'>' : '').'.',
            'Quote '.$quote->id.'.',
            'Reply with a price. This is not a payment.',
        ]);
        $mailbox = app(PlatformMailboxService::class);
        $sent = [];
        foreach ($recipients as $to) {
            try {
                $mailbox->send($to, $subject, $body, $user, [
                    'kind' => 'one_time_licence_quote',
                    'no_reply' => true,
                    'organization_id' => $org->id,
                    'quote_id' => $quote->id,
                ]);
                $sent[] = $to;
            } catch (\Throwable) {
                continue;
            }
        }

        return $sent;
    }

    protected function notifyOneTimeQuote(Organization $org, ?User $user): void
    {
        if (! $user) {
            return;
        }

        try {
            app(AdminNotificationService::class)->notifySuperAdmins($user, [
                'type' => 'licence_quote',
                'severity' => 'default',
                'title' => 'One-time payment quote',
                'message' => $org->org_name.' ('.$org->company_code.') asked for a Centrix one-time payment quote.',
            ]);
        } catch (\Throwable) {
            // The quote is already saved. A bell notification is extra.
        }
    }

    public function startTokenCheckout(Organization $org, ?string $phone = null): PlatformLicenceCheckout
    {
        $policy = app(BillingPolicy::class)->current();

        return PlatformLicenceCheckout::query()->create([
            'organization_id' => $org->id,
            'plan_id' => null,
            'months' => 0,
            'amount' => round((float) ($policy['token_pack_price_kes'] ?? 0), 2),
            'discount_percent' => 0,
            'phone_number' => $phone,
            'status' => 'pending',
        ]);
    }

    public function retryUnapplied(PlatformLicencePayment $payment): PlatformLicencePayment
    {
        if ($payment->status === 'applied' || ! $payment->organization_id) {
            return $payment;
        }

        $org = $payment->organization;
        if (! $org) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $org) {
            $locked = PlatformLicencePayment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'applied') {
                return $locked ?? $payment;
            }

            return $this->applyCredit($locked, $org, (float) $locked->amount);
        });
    }

    /**
     * Same callback Safaricom uses for shop STK. A licence prompt has no shop
     * STK row, so the receipt is applied to the subscription checkout.
     *
     * @param  array<string, mixed>  $values
     */
    public function acceptStkResult(?string $checkoutRequestId, int $resultCode, array $values, ?string $resultDesc = null): void
    {
        if ($checkoutRequestId === null || $checkoutRequestId === '') {
            return;
        }
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_licence_checkouts')) {
            return;
        }

        $checkout = PlatformLicenceCheckout::query()
            ->where('checkout_request_id', $checkoutRequestId)
            ->first();
        if (! $checkout || $checkout->status === 'paid') {
            return;
        }

        if ($resultCode !== 0) {
            $this->rememberStkResult($checkout, $resultCode, $resultDesc);

            return;
        }

        $transactionId = trim((string) ($values['MpesaReceiptNumber'] ?? ''));
        if ($transactionId === '') {
            return;
        }

        $org = $checkout->organization;
        $payment = $this->acceptC2b([
            'TransID' => $transactionId,
            'TransAmount' => $values['Amount'] ?? $checkout->amount,
            'MSISDN' => $values['PhoneNumber'] ?? $checkout->phone_number,
            'BillRefNumber' => $org?->company_code,
            'BusinessShortCode' => $this->settings()?->short_code,
        ]);

        if ($payment && $payment->status !== 'applied' && $org) {
            $payment->organization_id = $org->id;
            $payment->bill_ref_number = $payment->bill_ref_number ?: $org->company_code;
            $payment->account_reference = $payment->account_reference ?: $org->company_code;
            $payment->save();
            $this->retryUnapplied($payment->fresh());
        }
    }

    /**
     * @return array{status: string, checkout_id: ?int, stk_failed: bool, message: string, mpesa_receipt: ?string}
     */
    public function statusFor(Organization $org, ?int $checkoutId = null): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_licence_checkouts')) {
            return $this->statusPayload(null, 'Licence checkout is not available yet.');
        }

        $query = PlatformLicenceCheckout::query()->where('organization_id', $org->id);
        $checkout = $checkoutId
            ? (clone $query)->whereKey($checkoutId)->first()
            : (clone $query)->orderByDesc('id')->first();

        if ($checkout && $checkout->status === 'pending') {
            $this->attachUnmatchedPayment($checkout);
            $checkout->refresh();
        }

        return $this->statusPayload($checkout);
    }

    protected function attachUnmatchedPayment(PlatformLicenceCheckout $checkout): void
    {
        $org = $checkout->organization;
        if (! $org) {
            return;
        }

        $amount = (float) $checkout->amount;
        $code = strtoupper(preg_replace('/\s+/', '', (string) $org->company_code) ?? '');
        $phone = PhoneNumber::normalize((string) $checkout->phone_number);

        $payment = PlatformLicencePayment::query()
            ->where('status', '!=', 'applied')
            ->whereBetween('amount', [$amount - 1, $amount + 1])
            ->orderByDesc('id')
            ->get()
            ->first(function (PlatformLicencePayment $row) use ($org, $code, $phone) {
                if ((int) $row->organization_id === (int) $org->id) {
                    return true;
                }
                if ($row->organization_id) {
                    return false;
                }
                $reference = strtoupper((string) ($row->account_reference ?: $row->bill_ref_number));
                if ($code !== '' && $reference !== '' && (str_starts_with($code, $reference) || str_starts_with($reference, $code))) {
                    return true;
                }

                return $phone !== null && PhoneNumber::normalize((string) $row->phone_number) === $phone;
            });

        if (! $payment) {
            return;
        }

        $payment->organization_id = $org->id;
        $payment->bill_ref_number = $payment->bill_ref_number ?: $org->company_code;
        $payment->save();
        $this->retryUnapplied($payment->fresh());
    }

    /** @return array{status: string, checkout_id: ?int, stk_failed: bool, message: string, mpesa_receipt: ?string} */
    protected function statusPayload(?PlatformLicenceCheckout $checkout, ?string $fallback = null): array
    {
        $status = $checkout?->status ?? 'none';
        $failed = $checkout
            && $status !== 'paid'
            && $checkout->result_code !== null
            && (int) $checkout->result_code !== 0;

        $message = $fallback ?? match (true) {
            $status === 'paid' => 'M-Pesa payment confirmed. This organisation is paid.',
            $failed => $this->stkFailureMessage((int) $checkout->result_code, $checkout->result_desc),
            $status === 'pending' => 'Waiting for M-Pesa to confirm this payment.',
            default => 'No subscription payment is in progress.',
        };

        return [
            'status' => $status,
            'checkout_id' => $checkout?->id,
            'stk_failed' => $failed,
            'message' => $message,
            'mpesa_receipt' => $checkout?->mpesa_receipt,
        ];
    }

    protected function rememberStkResult(PlatformLicenceCheckout $checkout, int $resultCode, ?string $resultDesc): void
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('platform_licence_checkouts', 'result_code')) {
            return;
        }

        $checkout->result_code = $resultCode;
        $checkout->result_desc = $this->stkFailureMessage($resultCode, $resultDesc);
        $checkout->save();
    }

    protected function stkFailureMessage(int $resultCode, ?string $resultDesc): string
    {
        return match ($resultCode) {
            1032 => 'Payment cancelled on the phone. You can send the prompt again, or pay the Paybill.',
            1037 => 'The M-Pesa prompt timed out. Send it again, or pay the Paybill.',
            1 => 'Insufficient M-Pesa balance.',
            2001 => 'Wrong M-Pesa PIN. Send the prompt again.',
            default => trim((string) $resultDesc) !== '' ? trim((string) $resultDesc) : 'M-Pesa did not complete this payment.',
        };
    }

    protected function applyPendingCheckout(PlatformLicencePayment $payment, Organization $org, float $amount): ?PlatformLicencePayment
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('platform_licence_checkouts')) {
            return null;
        }

        $checkout = PlatformLicenceCheckout::query()
            ->where('organization_id', $org->id)
            ->where('status', 'pending')
            ->whereBetween('amount', [$amount - 1, $amount + 1])
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if (! $checkout) {
            return null;
        }

        $plan = $checkout->plan_id ? PlatformPlan::query()->find($checkout->plan_id) : null;
        if (! $plan) {
            return $this->applyTokenCheckout($payment, $org, $checkout, $amount);
        }
        if (! $this->planMatchesIndustry($org, $plan)) {
            $payment->note = 'industry_mismatch';
            $payment->save();

            return $payment;
        }

        $subscription = PlatformSubscription::query()
            ->where('organization_id', $org->id)
            ->lockForUpdate()
            ->first();

        if (! $subscription) {
            $subscription = PlatformSubscription::query()->create([
                'organization_id' => $org->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'is_trial' => false,
                'currency' => $plan->currency ?: 'KES',
                'license_basis' => $plan->license_basis ?: 'org',
                'amount' => $plan->monthly_price,
                'renewal_price' => $plan->monthly_price,
            ]);
        }

        $yearly = in_array(strtolower((string) $plan->interval), ['annual', 'yearly'], true)
            || (int) $checkout->months >= 12;
        $renewal = $yearly ? (float) ($plan->yearly_price ?: $checkout->amount) : (float) ($plan->monthly_price ?: $checkout->amount);
        $days = $yearly ? 365 : max(1, (int) $checkout->months) * 30;
        $keptRenewal = (float) ($subscription->renewal_price ?? 0);
        $renewingSameAmount = $keptRenewal > 0 && abs($keptRenewal - (float) $checkout->amount) <= 1;
        $subscription->plan_id = $plan->id;
        $subscription->is_trial = false;
        $subscription->status = 'active';
        if (! $renewingSameAmount) {
            $subscription->amount = $renewal;
            $subscription->renewal_price = $renewal;
        }
        if (\Illuminate\Support\Facades\Schema::hasColumn('platform_subscriptions', 'billing_cycle')) {
            $subscription->billing_cycle = $yearly ? 'yearly' : 'monthly';
        }
        $subscription->save();
        $this->extendSubscription($subscription, [
            'product' => $yearly ? 'yearly' : 'monthly',
            'price' => (float) $checkout->amount,
            'days' => $days,
        ]);
        $this->applyPlanEntitlements($org, $plan);

        $checkout->status = 'paid';
        $checkout->mpesa_receipt = $payment->mpesa_transaction_id;
        $checkout->save();

        $payment->fill([
            'status' => 'applied',
            'product' => $yearly ? 'yearly' : 'monthly',
            'days_added' => $days,
            'amount_applied' => $amount,
            'balance_after' => 0,
            'period_end_after' => $subscription->fresh()?->current_period_end?->toDateString(),
            'note' => 'plan_checkout',
        ])->save();

        return $payment->fresh();
    }

    protected function applyTokenCheckout(
        PlatformLicencePayment $payment,
        Organization $org,
        PlatformLicenceCheckout $checkout,
        float $amount,
    ): PlatformLicencePayment {
        $policy = app(BillingPolicy::class)->current();
        if (($policy['tokens_enabled'] ?? true) !== true) {
            $payment->fill([
                'status' => 'unmatched',
                'note' => 'tokens_disabled',
            ])->save();

            return $payment;
        }
        $packPrice = (float) ($policy['token_pack_price_kes'] ?? 0);
        $packTokens = (int) ($policy['token_pack_tokens'] ?? 0);
        $value = max(1, (int) ($policy['token_value_kes'] ?? 1));
        $tokens = $packPrice > 0 && abs($amount - $packPrice) <= 1
            ? $packTokens
            : max(0, (int) round($amount / $value));

        $balanceRow = PlatformLicenceBalance::query()->firstOrCreate(
            ['organization_id' => $org->id],
            ['balance' => 0],
        );
        $balanceRow = PlatformLicenceBalance::query()->whereKey($balanceRow->id)->lockForUpdate()->first();
        $beforeTokens = (int) $balanceRow->token_balance;
        $balanceRow->token_balance = $beforeTokens + $tokens;
        $balanceRow->save();
        app(TokenLowBalanceNotifier::class)->afterChange((int) $balanceRow->organization_id, $beforeTokens, $beforeTokens + $tokens);

        $checkout->status = 'paid';
        $checkout->mpesa_receipt = $payment->mpesa_transaction_id;
        $checkout->save();

        $payment->fill([
            'status' => 'applied',
            'product' => 'token',
            'days_added' => null,
            'amount_applied' => $amount,
            'balance_after' => (int) $balanceRow->token_balance,
            'note' => 'token_checkout',
        ])->save();

        return $payment->fresh();
    }

    protected function applyPlanEntitlements(Organization $org, PlatformPlan $plan): void
    {
        $workspaces = array_values(array_filter($plan->workspace_keys ?? []));
        if ($workspaces !== []) {
            $applications = [];
            foreach (\App\Services\Erp\ApplicationProvisioner::ids() as $id) {
                $applications[$id] = in_array($id, $workspaces, true);
            }
            $applications['admin'] = true;
            $modules = app(\App\Services\Erp\ApplicationProvisioner::class)
                ->enabledModulesFromApplications($applications);
            $org->enabled_modules = app(\App\Services\OrganizationProvisioningService::class)
                ->normalizeEnabledModules($modules);
        }

        $sections = array_values(array_filter($plan->section_keys ?? []));
        if ($sections !== []) {
            $map = \App\Services\Erp\BackofficeSections::DEFAULTS;
            foreach (array_keys($map) as $key) {
                $map[$key] = in_array($key, $sections, true);
            }
            $settings = $org->module_settings ?? [];
            $sales = is_array($settings['sales'] ?? null) ? $settings['sales'] : [];
            $sales['backoffice_sections'] = $map;
            $settings['sales'] = $sales;
            $org->module_settings = $settings;
        }

        $org->save();
    }

    /** @param  array{product: string, price: float, days: int}  $product */
    protected function extendSubscription(PlatformSubscription $subscription, array $product): void
    {
        $now = now();
        $current = $subscription->current_period_end ?? $subscription->trial_ends_at;
        $base = $current ? Carbon::parse($current) : $now->copy();
        if ($base->lt($now)) {
            $base = $now->copy();
        }

        $end = $product['product'] === 'yearly'
            ? $base->copy()->addYear()
            : $base->copy()->addDays((int) $product['days']);

        $stillCurrent = $current && Carbon::parse($current)->gte($now);
        $subscription->forceFill([
            'status' => 'active',
            'is_trial' => false,
            'trial_ends_at' => null,
            'current_period_start' => $stillCurrent && $subscription->current_period_start
                ? $subscription->current_period_start->format('Y-m-d H:i:s')
                : $now->format('Y-m-d H:i:s'),
            'current_period_end' => $end->format('Y-m-d H:i:s'),
        ])->save();
    }

    protected function isPlatform(Organization $org): bool
    {
        $platformCode = (string) config('erp.platform_company_code', 'PLATFORM');

        return strcasecmp((string) $org->company_code, $platformCode) === 0;
    }

    protected function blankToNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
