<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformBillingSetting;
use App\Models\PlatformLicenceBalance;
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
        $row->fill([
            'organization_id' => $platform?->id,
            'short_code' => $this->blankToNull($data['short_code'] ?? null),
            'account_name_prefix' => $this->blankToNull($data['account_name_prefix'] ?? null),
        ]);
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

        $tokenDays = (int) ($paybill['token_pack_days'] ?? 0);
        $offers[] = [
            'id' => 'token',
            'title' => 'Token subscription',
            'price' => $paybill['token_pack_price'] ?? null,
            'currency' => $currency,
            'period' => $tokenDays > 0 ? $tokenDays.' days' : 'A prepaid block of days',
            'summary' => 'A token is prepaid licence time, not an AI credit. Pay the token amount and Centrix stays open for that many days.',
            'features' => array_merge(
                [$tokenDays > 0 ? $tokenDays.' days of full Centrix' : 'A short block of full Centrix'],
                ['Use it when you do not want a monthly or yearly plan'],
                $unlocked,
            ),
        ];

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
        if ($tokenPrice !== null && (float) $tokenPrice > 0 && $tokenDays > 0) {
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

        $balance = round((float) $balanceRow->balance + $amount, 2);
        $subscription = PlatformSubscription::query()
            ->with('plan')
            ->where('organization_id', $org->id)
            ->lockForUpdate()
            ->first();

        $catalogue = $subscription?->plan ? $this->priceCatalogue($subscription->plan) : [];
        if (! $subscription || ! $subscription->plan || $catalogue === []) {
            $balanceRow->balance = $balance;
            $balanceRow->save();
            $payment->fill([
                'status' => 'unmatched',
                'balance_after' => $balance,
                'note' => $subscription ? 'no_price' : 'no_plan',
            ])->save();

            return $payment->fresh();
        }

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
            'note' => $products === [] ? 'awaiting_price' : (count($products) > 1 ? 'stacked' : null),
        ])->save();

        return $payment->fresh();
    }

    /** @param  array{product: string, price: float, days: int}  $product */
    protected function extendSubscription(PlatformSubscription $subscription, array $product): void
    {
        $today = Carbon::today()->startOfDay();
        $current = $subscription->current_period_end ?? $subscription->trial_ends_at;
        $base = $current ? Carbon::parse($current)->startOfDay() : $today->copy();
        if ($base->lt($today)) {
            $base = $today->copy();
        }

        $end = $product['product'] === 'yearly'
            ? $base->copy()->addYear()
            : $base->copy()->addDays((int) $product['days']);

        $stillCurrent = $current && Carbon::parse($current)->startOfDay()->gte($today);
        $subscription->forceFill([
            'status' => 'active',
            'is_trial' => false,
            'trial_ends_at' => null,
            'current_period_start' => $stillCurrent && $subscription->current_period_start
                ? $subscription->current_period_start->toDateString()
                : $today->toDateString(),
            'current_period_end' => $end->toDateString(),
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
