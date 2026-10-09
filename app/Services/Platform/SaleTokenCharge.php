<?php

namespace App\Services\Platform;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Spend organisation tokens once per completed sale, at the moment that sale is saved online.
 * Offline till sales wait until sync uploads the order. A replay of the same upload does not charge again.
 */
class SaleTokenCharge
{
    public function __construct(
        protected OrganizationLicenseService $licenses,
        protected BillingPolicy $policy,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function charge(User $user, array $meta): array
    {
        if ((int) ($meta['tokens_charged'] ?? 0) > 0) {
            return $meta;
        }
        if ((int) ($meta['supersedes_sale_id'] ?? 0) > 0) {
            return $meta;
        }

        $orgId = (int) ($user->organization_id ?? 0);
        if ($orgId <= 0) {
            return $meta;
        }

        $org = $user->relationLoaded('organization')
            ? $user->organization
            : Organization::query()->find($orgId);
        if (! $org) {
            return $meta;
        }

        $license = $this->licenses->resolveForOrganization($org);
        if ($this->licenses->accessMode($license) !== 'token') {
            return $meta;
        }

        $cost = $this->policy->saleCost();
        if ($cost <= 0) {
            return $meta;
        }

        if (! $this->licenses->reserveTokens($orgId, $cost)) {
            throw new HttpResponseException(response()->json([
                'message' => 'This sale needs '.$cost.' Centrix token'.($cost === 1 ? '' : 's').'. It stays on this till until the organisation tops up and sync runs again.',
                'code' => 'organization_tokens_depleted',
                'license' => $this->licenses->resolveForOrganization($org),
            ], 403));
        }

        $meta['tokens_charged'] = $cost;

        return $meta;
    }
}
