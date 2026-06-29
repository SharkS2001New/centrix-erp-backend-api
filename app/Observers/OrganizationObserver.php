<?php

namespace App\Observers;

use App\Models\Organization;
use App\Services\Cache\CapabilitiesCacheInvalidator;
use App\Services\Cache\OrganizationCache;

class OrganizationObserver
{
    public function updated(Organization $organization): void
    {
        CapabilitiesCacheInvalidator::forOrganization((int) $organization->id);
    }
}
