<?php

namespace App\Services\Erp;

use App\Models\Organization;
use App\Models\User;

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
            ? (new CapabilityGate)->forOrganization($org)
            : new CapabilityGate;
    }
}
