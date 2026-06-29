<?php

namespace App\Observers;

use App\Models\Organization;
use App\Services\Cache\CapabilitiesCacheInvalidator;

class OrganizationObserver
{
    public function updated(Organization $organization): void
    {
        CapabilitiesCacheInvalidator::forOrganization((int) $organization->id);
    }
}
