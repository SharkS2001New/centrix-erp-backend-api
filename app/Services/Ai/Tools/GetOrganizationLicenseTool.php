<?php

namespace App\Services\Ai\Tools;

use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Ai\Tools\Concerns\ResolvesAiToolOrganization;
use App\Services\Auth\UserPermissionService;
use App\Services\Erp\ErpContext;
use App\Services\Platform\OrganizationLicenseService;
use Illuminate\Validation\ValidationException;

/**
 * Organization Centrix licence / subscription status for the logged-in user's company.
 */
class GetOrganizationLicenseTool implements AiToolInterface
{
    use ResolvesAiToolOrganization;

    public function __construct(
        protected ErpContext $erp,
        protected UserPermissionService $permissions,
        protected OrganizationLicenseService $licenses,
    ) {}

    public function name(): string
    {
        return 'get_organization_license';
    }

    public function description(): string
    {
        return 'Get this organization’s Centrix licence / subscription: plan name, status, expiry date, '
            .'days remaining, trial flag, and whether it is about to expire (within the warning window). '
            .'Use for "when does my licence expire", "subscription status", "are we on trial", '
            .'"how many days left on Centrix", or "licence info". '
            .'Answers the logged-in user’s own organization only — never invent expiry dates.';
    }

    public function parametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => (object) [],
        ];
    }

    public function execute(User $user, array $arguments): array
    {
        $organization = $this->resolveOrganizationForUser($user);
        if (! $organization) {
            throw ValidationException::withMessages([
                'organization' => ['Your account is not linked to an organization.'],
            ]);
        }
        if (! $this->assertSameOrganization($user, $organization)) {
            throw ValidationException::withMessages([
                'organization' => ['You cannot query another organization.'],
            ]);
        }

        $gate = $this->erp->gateForUser($user);
        $canView = $this->permissions->hasPermission($user, 'ai.assist', $gate)
            || $this->permissions->hasPermission($user, 'settings.view', $gate)
            || $this->permissions->hasPermission($user, 'admin.access', $gate)
            || $user->is_super_admin;
        if (! $canView) {
            return [
                'error' => true,
                'message' => 'You do not have permission to view licence information.',
            ];
        }

        if ($this->licenses->isPlatformOrganization($organization)) {
            return [
                'source' => 'organization_license',
                'is_platform_tenant' => true,
                'organization' => [
                    'id' => (int) $organization->id,
                    'org_name' => (string) ($organization->org_name ?? ''),
                    'company_code' => (string) ($organization->company_code ?? ''),
                ],
                'license' => null,
                'message' => 'This is the Centrix platform tenant — it is not gated by a customer subscription licence.',
                'tip' => 'Say clearly that platform tenants are not subscription-gated. Do not invent an expiry date.',
            ];
        }

        $license = $this->licenses->resolveForOrganization($organization);
        $days = $license['days_remaining'] ?? null;
        $warningDays = (int) ($license['warning_days'] ?? OrganizationLicenseService::WARNING_DAYS);
        $expired = $this->licenses->isExpired($license);
        $aboutToExpire = ! $expired
            && is_numeric($days)
            && (int) $days >= 0
            && (int) $days <= $warningDays;

        $sub = PlatformSubscription::query()
            ->with('plan')
            ->where('organization_id', $organization->id)
            ->first();

        $payload = [
            'source' => 'organization_license',
            'is_platform_tenant' => false,
            'organization' => [
                'id' => (int) $organization->id,
                'org_name' => (string) ($organization->org_name ?? ''),
                'company_code' => (string) ($organization->company_code ?? ''),
            ],
            'license' => $license,
            'expired' => $expired,
            'about_to_expire' => $aboutToExpire,
            'expires_today' => is_numeric($days) && (int) $days === 0,
            'subscription' => $sub ? [
                'id' => (int) $sub->id,
                'status' => (string) $sub->status,
                'plan_name' => $sub->plan?->name,
                'seat_count' => $sub->seat_count !== null ? (int) $sub->seat_count : null,
                'is_trial' => (bool) $sub->is_trial,
                'current_period_start' => $sub->current_period_start?->toDateString(),
                'current_period_end' => $sub->current_period_end?->toDateString(),
                'trial_ends_at' => $sub->trial_ends_at?->toDateString(),
                'currency' => (string) ($sub->currency ?? 'KES'),
                'renewal_price' => $sub->renewal_price !== null ? (float) $sub->renewal_price : null,
                'license_basis' => (string) ($sub->license_basis ?? 'org'),
            ] : null,
            'tip' => 'Answer from this licence payload. State plan, status, expiry date, and days remaining clearly. '
                .'If about_to_expire is true, warn that renewal is due soon. '
                .'If expired or missing, say access will be blocked until a Centrix administrator renews. '
                .'Never invent dates. Do not speak raw paths — say contact your Centrix administrator to renew.',
        ];

        if (($license['status'] ?? '') === 'missing') {
            $payload['message'] = 'No active Centrix subscription is on file for this organization.';
        }

        return $payload;
    }
}
