<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformLicenceBalance;
use App\Models\PlatformSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

class OrganizationLicenseService
{
    public const WARNING_DAYS = 7;

    public const EXPIRED_CODES = [
        'organization_license_expired',
        'organization_subscription_required',
        'license_expired',
        'subscription_expired',
        'organization_subscription_expired',
    ];

    public const ACTIVE_STATUSES = ['active', 'trialing', 'past_due'];

    public function isPlatformOrganization(?Organization $org): bool
    {
        if (! $org) {
            return false;
        }

        $platformCode = config('erp.platform_company_code', 'PLATFORM');

        return strcasecmp((string) $org->company_code, $platformCode) === 0;
    }

    public function resolveForOrganization(?Organization $org): ?array
    {
        if (! $org) {
            return null;
        }

        if ($this->isPlatformOrganization($org)) {
            return null;
        }

        /** @var PlatformSubscription|null $sub */
        $sub = PlatformSubscription::query()
            ->with('plan')
            ->where('organization_id', $org->id)
            ->first();

        if (! $sub) {
            $missing = [
                'status' => 'missing',
                'expires_at' => null,
                'is_trial' => false,
                'days_remaining' => null,
                'warning_days' => self::WARNING_DAYS,
                'plan_name' => null,
                'subscription_id' => null,
                'token_balance' => $this->tokenBalanceFor($org),
                'sale_tokens' => app(BillingPolicy::class)->saleCost(),
                'write_costs' => app(BillingPolicy::class)->current()['write_costs'],
                'modules' => [],
                'paybill' => app(PlatformPaybillLicenceService::class)->instructionsFor($org, null),
                'offers' => app(PlatformPaybillLicenceService::class)->paymentOffers($org, null),
                'plans' => app(PlatformPaybillLicenceService::class)->monthlyPlanChoices(),
                'duration_discount' => app(PlatformPaybillLicenceService::class)->durationDiscount(),
            ];
            $missing['access'] = $this->accessMode($missing);

            return $missing;
        }

        $window = $this->periodWindow($sub);
        $status = $window['status'];
        $daysRemaining = $window['days_remaining'];

        $license = [
            'status' => $status ?: 'active',
            'starts_at' => $window['starts_at'],
            'expires_at' => $window['expires_at'],
            'seconds_remaining' => $window['seconds_remaining'],
            'is_trial' => (bool) ($sub->is_trial || $status === 'trialing'),
            'days_remaining' => $daysRemaining,
            'warning_days' => self::WARNING_DAYS,
            'plan_name' => $sub->plan?->name,
            'subscription_id' => $sub->id,
            'token_balance' => $this->tokenBalanceFor($org),
            'sale_tokens' => app(BillingPolicy::class)->saleCost(),
            'write_costs' => app(BillingPolicy::class)->current()['write_costs'],
            'modules' => $this->licensedModules($sub),
        ];
        $license['access'] = $this->accessMode($license);
        $license['paybill'] = app(PlatformPaybillLicenceService::class)->instructionsFor($org, $sub);
        $license['offers'] = app(PlatformPaybillLicenceService::class)->paymentOffers($org, $sub);
        $license['plans'] = app(PlatformPaybillLicenceService::class)->monthlyPlanChoices();
        $license['duration_discount'] = app(PlatformPaybillLicenceService::class)->durationDiscount();

        return $license;
    }

    /** True when the org must be locked out (no usable active subscription). */
    public function isExpired(?array $license): bool
    {
        if (! $license) {
            return false;
        }

        $status = strtolower((string) ($license['status'] ?? ''));
        if ($status === 'scheduled') {
            return false;
        }
        if (in_array($status, ['missing', 'expired', 'cancelled', 'inactive'], true)) {
            return true;
        }

        if (isset($license['days_remaining']) && $license['days_remaining'] < 0) {
            return true;
        }

        if ($status !== '' && ! in_array($status, self::ACTIVE_STATUSES, true)) {
            return true;
        }

        return false;
    }

    /**
     * trial = free trial still inside its dates, full system.
     * full = paid monthly or yearly. token = shilling balance.
     * free = no subscription. expired = trial or paid period ended.
     * Free and expired users stay signed in with a limited set of pages.
     */
    public function accessMode(?array $license): string
    {
        if ($license === null) {
            return 'full';
        }

        $status = strtolower((string) ($license['status'] ?? ''));
        if (($license['status'] ?? '') === 'scheduled') {
            return 'expired';
        }

        $onTrial = $status === 'trialing' || ! empty($license['is_trial']);

        if ($onTrial && ! $this->isExpired($license)) {
            return 'trial';
        }

        $calendarPaid = $status !== 'missing'
            && ! $onTrial
            && ! $this->isExpired($license);

        if ($calendarPaid) {
            return 'full';
        }

        if ((int) ($license['token_balance'] ?? 0) > 0) {
            return 'token';
        }

        if ($status === 'missing') {
            return 'free';
        }

        if ($this->isExpired($license)) {
            return 'expired';
        }

        return 'full';
    }

    /** Applications on the plan the organisation is paying to keep. */
    public function licensedModules(?PlatformSubscription $subscription): array
    {
        $keys = $subscription?->workspace_keys ?? $subscription?->plan?->workspace_keys ?? [];
        if (! is_array($keys)) {
            return [];
        }

        $modules = [];
        foreach ($keys as $key) {
            $id = strtolower(trim((string) $key));
            if ($id !== '') {
                $modules[] = $id;
            }
        }

        return array_values(array_unique($modules));
    }

    public function tokenBalanceFor(?Organization $org): int
    {
        if (! $org || ! \Illuminate\Support\Facades\Schema::hasTable('platform_licence_balances')) {
            return 0;
        }
        if (! \Illuminate\Support\Facades\Schema::hasColumn('platform_licence_balances', 'token_balance')) {
            return 0;
        }

        return (int) (PlatformLicenceBalance::query()
            ->where('organization_id', $org->id)
            ->value('token_balance') ?? 0);
    }

    /** Spend tokens for a customer create, update, or delete. A cost of 0 does not touch the balance. */
    public function reserveTokens(int $organizationId, int $amount): bool
    {
        if ($organizationId <= 0) {
            return false;
        }
        if ($amount <= 0) {
            return true;
        }

        $before = (int) (PlatformLicenceBalance::query()
            ->where('organization_id', $organizationId)
            ->value('token_balance') ?? 0);

        $spent = PlatformLicenceBalance::query()
            ->where('organization_id', $organizationId)
            ->where('token_balance', '>=', $amount)
            ->decrement('token_balance', $amount) > 0;

        if ($spent) {
            app(TokenLowBalanceNotifier::class)->afterChange($organizationId, $before, $before - $amount);
        }

        return $spent;
    }

    public function refundTokens(int $organizationId, int $amount): void
    {
        if ($organizationId <= 0 || $amount <= 0) {
            return;
        }

        $before = (int) (PlatformLicenceBalance::query()
            ->where('organization_id', $organizationId)
            ->value('token_balance') ?? 0);

        PlatformLicenceBalance::query()
            ->where('organization_id', $organizationId)
            ->increment('token_balance', $amount);

        app(TokenLowBalanceNotifier::class)->afterChange($organizationId, $before, $before + $amount);
    }

    /** Spend one token for a create, update, or delete. Returns false when the balance is empty. */
    public function reserveToken(int $organizationId): bool
    {
        return $this->reserveTokens($organizationId, 1);
    }

    public function refundToken(int $organizationId): void
    {
        $this->refundTokens($organizationId, 1);
    }

    public function needsPayment(?array $license): bool
    {
        return in_array($this->accessMode($license), ['free', 'expired'], true);
    }

    /** Routes a free or expired organisation may still call. Shop M-Pesa stays separate. */
    public function allowsLimitedRoute(string $path): bool
    {
        $path = ltrim($path, '/');
        if (str_starts_with($path, 'api/v1/')) {
            $path = substr($path, strlen('api/v1/'));
        }
        $path = trim($path, '/');

        if ($path === '' || str_starts_with($path, 'auth/')) {
            return true;
        }

        foreach ([
            'erp/capabilities',
            'erp/organization/profile',
            'erp/organization/logo',
            'erp/organization/subscription',
            'erp/organization/licence',
            'erp/organization/billing',
            'products',
            'categories',
            'sub-categories',
            'uoms',
            'customers',
            'suppliers',
            'branches',
            'notifications',
        ] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        if ($path === 'reports') {
            return true;
        }

        if (preg_match('#^reports/([a-z0-9\-]+)$#', $path, $matches) === 1) {
            return in_array($matches[1], self::LIMITED_SALES_REPORTS, true);
        }

        return false;
    }

    /** @var list<string> */
    public const LIMITED_SALES_REPORTS = [
        'daily-sales',
        'sales-by-product',
        'sales-by-supplier',
        'sales-by-user',
        'sales-by-customer',
        'sales-by-channel',
        'sales-pipeline',
        'vat-collected',
        'category-sales',
        'discount-summary',
        'payment-collection',
        'credit-outstanding',
        'till-sessions',
        'eod-cashier',
        'eod-report',
        'returns',
    ];

    public function assertUsable(?Organization $org, ?User $user = null): void
    {
        if ($user?->is_super_admin) {
            return;
        }

        if ($this->isPlatformOrganization($org)) {
            return;
        }

        $license = $this->resolveForOrganization($org);
        if (! $this->isExpired($license)) {
            return;
        }

        if ($org) {
            $this->revokeOrganizationSessions($org);
        }

        $missing = ! $license || ($license['status'] ?? '') === 'missing';

        throw new HttpResponseException(response()->json([
            'message' => $missing
                ? 'This organization does not have an active Centrix subscription. Contact your Centrix administrator to activate a plan.'
                : 'This organization’s Centrix licence has expired. Contact your Centrix administrator to renew or extend.',
            'code' => $missing ? 'organization_subscription_required' : 'organization_license_expired',
            'license' => $license,
        ], 403));
    }

    public function revokeOrganizationSessions(Organization $org): void
    {
        $userIds = User::query()
            ->where('organization_id', $org->id)
            ->pluck('id');

        if ($userIds->isEmpty()) {
            return;
        }

        PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->where('name', 'not like', \App\Support\AttendanceAgentToken::NAME_PREFIX.'%')
            ->where('name', 'not like', \App\Support\KraAgentToken::NAME_PREFIX.'%')
            ->delete();
    }

    /** @param  array<string, mixed>  $payload */
    public function createOrUpdateForOrganization(Organization $org, array $payload): PlatformSubscription
    {
        $planId = $payload['plan_id'] ?? null;
        $plan = $planId ? \App\Models\PlatformPlan::query()->find($planId) : null;

        $isTrial = (bool) ($payload['is_trial'] ?? (($payload['status'] ?? '') === 'trialing'));
        $status = $payload['status'] ?? ($isTrial ? 'trialing' : 'active');
        $start = $this->normalizePeriodInstant($payload['current_period_start'] ?? now(), false);
        $end = $this->normalizePeriodInstant(
            $payload['current_period_end']
                ?? $payload['trial_ends_at']
                ?? ($isTrial
                    ? now()->addDays((int) ($payload['trial_days'] ?? 14))
                    : $this->periodEndForPlanInterval($start, $plan?->interval)),
            true,
        );

        $attrs = [
            'plan_id' => $plan?->id,
            'status' => $status,
            'seat_count' => (int) ($payload['seat_count'] ?? $plan?->seat_limit ?? 1),
            'current_period_start' => $start,
            'current_period_end' => $end,
            'is_trial' => $isTrial,
            'trial_ends_at' => $isTrial ? $end : null,
            'first_payment_price' => $payload['first_payment_price'] ?? $plan?->first_payment_price ?? $plan?->price,
            'renewal_price' => $payload['renewal_price'] ?? $plan?->renewal_price ?? $plan?->price,
            'amount' => $payload['amount'] ?? $payload['renewal_price'] ?? $plan?->renewal_price ?? $plan?->price,
            'currency' => $payload['currency'] ?? $plan?->currency ?? 'KES',
            'license_basis' => $payload['license_basis'] ?? $plan?->license_basis ?? 'org',
            'workspace_keys' => $payload['workspace_keys'] ?? $plan?->workspace_keys,
            'module_keys' => $payload['module_keys'] ?? $plan?->module_keys,
            'contract_id' => $payload['contract_id'] ?? null,
        ];

        if (array_key_exists('invoice_id', $payload)) {
            $attrs['invoice_id'] = $payload['invoice_id'];
        }
        if (Schema::hasColumn('platform_subscriptions', 'initial_invoice_id')
            && array_key_exists('initial_invoice_id', $payload)
        ) {
            $attrs['initial_invoice_id'] = $payload['initial_invoice_id'];
        }

        $with = ['plan', 'organization', 'invoice'];
        if (Schema::hasColumn('platform_subscriptions', 'initial_invoice_id')) {
            $with[] = 'initialInvoice';
        }

        return PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $org->id],
            $attrs,
        )->load($with);
    }

    /** Next period end from start + plan interval (yearly/annual → +1 calendar year). */
    public function periodEndForPlanInterval(string $start, ?string $interval): string
    {
        $key = strtolower(trim((string) $interval));
        $from = Carbon::parse($start);
        $end = in_array($key, ['annual', 'yearly', 'year'], true)
            ? $from->copy()->addYear()
            : $from->copy()->addDays(30);
        if ($from->format('H:i:s') === '00:00:00') {
            $end = $end->endOfDay();
        }

        return $end->format('Y-m-d H:i:s');
    }

    /**
     * A date-only value lasts the whole calendar day. A value with an hour is exact.
     */
    public function licenceMoment(mixed $value, bool $endOfDayWhenDateOnly = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string) ($value instanceof Carbon ? $value->format('Y-m-d H:i:s') : $value));
        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $parsed = Carbon::parse($text);

            return $endOfDayWhenDateOnly ? $parsed->endOfDay() : $parsed->startOfDay();
        }

        return Carbon::parse($text);
    }

    public function normalizePeriodInstant(mixed $value, bool $isEnd): ?string
    {
        $moment = $this->licenceMoment($value, $isEnd);

        return $moment?->format('Y-m-d H:i:s');
    }

    /**
     * Open from the start hour, close at the end hour. Cancelled stays closed.
     *
     * @return array{status: string, starts_at: ?string, expires_at: ?string, seconds_remaining: ?int, days_remaining: ?int}
     */
    public function periodWindow(PlatformSubscription $sub, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $expiresAt = $this->licenceMoment($sub->trial_ends_at ?? $sub->current_period_end, true);
        $startsAt = $this->licenceMoment($sub->current_period_start, false);
        $stored = strtolower((string) $sub->status);
        $status = $stored !== '' ? $stored : 'active';

        if ($stored === 'cancelled') {
            $status = 'expired';
        } elseif ($startsAt && $now->lt($startsAt) && in_array($stored, self::ACTIVE_STATUSES, true)) {
            $status = 'scheduled';
        } elseif ($expiresAt && $now->greaterThanOrEqualTo($expiresAt) && in_array($stored, [...self::ACTIVE_STATUSES, 'expired', ''], true)) {
            $status = 'expired';
        }

        $seconds = $expiresAt ? (int) $now->diffInSeconds($expiresAt, false) : null;

        return [
            'status' => $status,
            'starts_at' => $startsAt?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'seconds_remaining' => $seconds,
            'days_remaining' => $seconds === null ? null : (int) floor($seconds / 86400),
        ];
    }

    /** Status to store when a revoked licence is restored. */
    public function restoredStatus(PlatformSubscription $sub): string
    {
        if ($sub->is_trial) {
            $end = $this->licenceMoment($sub->trial_ends_at ?? $sub->current_period_end, true);

            return ($end && now()->greaterThanOrEqualTo($end)) ? 'expired' : 'trialing';
        }

        $end = $this->licenceMoment($sub->current_period_end ?? $sub->trial_ends_at, true);
        if ($end && now()->greaterThanOrEqualTo($end)) {
            return 'expired';
        }

        return 'active';
    }
}
