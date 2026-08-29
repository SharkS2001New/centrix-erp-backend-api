<?php

namespace App\Services\Erp;

use App\Models\Organization;

/**
 * Central module enablement checks for Centrix ERP.
 */
class ModuleService
{
    public function __construct(protected CapabilityGate $gate) {}

    public static function forOrganization(Organization $organization): self
    {
        return new self(app(CapabilityGate::class)->forOrganization($organization));
    }

    public static function forOrganizationId(int $organizationId): self
    {
        $organization = Organization::query()->findOrFail($organizationId);

        return self::forOrganization($organization);
    }

    public function isEnabled(string $moduleKey): bool
    {
        return $this->gate->enabled($moduleKey);
    }

    public function centrixPaymentsEnabled(): bool
    {
        return $this->gate->centrixPaymentsPlatformEnabled();
    }

    public function gate(): CapabilityGate
    {
        return $this->gate;
    }
}
