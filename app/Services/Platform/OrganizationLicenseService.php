<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            return [
                'status' => 'missing',
                'expires_at' => null,
                'is_trial' => false,
                'days_remaining' => null,
                'warning_days' => self::WARNING_DAYS,
                'plan_name' => null,
                'subscription_id' => null,
            ];
        }

        $expiresAt = $sub->trial_ends_at ?? $sub->current_period_end;
        $end = $expiresAt ? Carbon::parse($expiresAt)->endOfDay() : null;
        $daysRemaining = $end ? (int) Carbon::today()->diffInDays($end, false) : null;

        $status = strtolower((string) $sub->status);
        if (in_array($status, self::ACTIVE_STATUSES, true) && $daysRemaining !== null && $daysRemaining < 0) {
            $status = 'expired';
        }
        if (in_array($status, ['cancelled'], true)) {
            $status = 'expired';
        }

        return [
            'status' => $status ?: 'active',
            'expires_at' => $expiresAt?->toDateString(),
            'is_trial' => (bool) ($sub->is_trial || $status === 'trialing'),
            'days_remaining' => $daysRemaining,
            'warning_days' => self::WARNING_DAYS,
            'plan_name' => $sub->plan?->name,
            'subscription_id' => $sub->id,
        ];
    }

    /** True when the org must be locked out (no usable active subscription). */
    public function isExpired(?array $license): bool
    {
        if (! $license) {
            return false;
        }

        $status = strtolower((string) ($license['status'] ?? ''));
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
            ->delete();
    }

    /** @param  array<string, mixed>  $payload */
    public function createOrUpdateForOrganization(Organization $org, array $payload): PlatformSubscription
    {
        $planId = $payload['plan_id'] ?? null;
        $plan = $planId ? \App\Models\PlatformPlan::query()->find($planId) : null;

        $isTrial = (bool) ($payload['is_trial'] ?? (($payload['status'] ?? '') === 'trialing'));
        $status = $payload['status'] ?? ($isTrial ? 'trialing' : 'active');
        $start = $payload['current_period_start'] ?? now()->toDateString();
        $end = $payload['current_period_end']
            ?? $payload['trial_ends_at']
            ?? ($isTrial
                ? now()->addDays((int) ($payload['trial_days'] ?? 14))->toDateString()
                : now()->addDays(($plan?->interval === 'annual') ? 365 : 30)->toDateString());

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

        return PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $org->id],
            $attrs,
        )->load('plan', 'organization');
    }
}
