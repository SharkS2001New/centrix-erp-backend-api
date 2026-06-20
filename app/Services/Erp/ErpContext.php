<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;

class ErpContext
{
    public function organizationForUser(?User $user): ?Organization
    {
        if (! $user?->organization_id) {
            return null;
        }

        return Organization::find($user->organization_id);
    }

    public function gateForUser(?User $user): CapabilityGate
    {
        $org = $this->organizationForUser($user);

        return $org
            ? $this->gateForOrganization($org)
            : new CapabilityGate;
    }

    public function gateForOrganization(Organization $organization): CapabilityGate
    {
        return (new CapabilityGate)->forOrganization($organization);
    }

    public function resolveOrganization(Request $request): Organization
    {
        if ($actingId = $request->attributes->get('acting_organization_id')) {
            return Organization::findOrFail($actingId);
        }

        $user = $request->user();
        if (! $user?->organization_id) {
            abort(403, 'Organization context required.');
        }

        return Organization::findOrFail($user->organization_id);
    }

    public function gateForRequest(Request $request): CapabilityGate
    {
        return $this->gateForOrganization($this->resolveOrganization($request));
    }
}
