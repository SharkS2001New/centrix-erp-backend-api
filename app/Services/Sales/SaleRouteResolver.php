<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Services\Erp\CapabilityGate;

class SaleRouteResolver
{
    protected function findCustomer(?int $organizationId, ?int $customerNum): ?Customer
    {
        if (! $customerNum) {
            return null;
        }

        $query = Customer::query()->where('customer_num', $customerNum);
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        return $query->first();
    }

    public function resolveFromCustomer(
        ?int $customerNum,
        CapabilityGate $gate,
        string $channel = 'backend',
        ?int $explicitRouteId = null,
    ): ?int {
        $organizationId = (int) ($gate->organization()?->id ?? 0) ?: null;

        if ($channel === 'mobile' && $customerNum) {
            $customer = $this->findCustomer($organizationId, $customerNum);
            if ($customer?->route_id) {
                return (int) $customer->route_id;
            }
        }

        if ($explicitRouteId) {
            return (int) $explicitRouteId;
        }

        if (! $customerNum) {
            return null;
        }

        $customer = $this->findCustomer($organizationId, $customerNum);
        if (! $customer?->route_id) {
            return null;
        }

        if ($channel === 'mobile') {
            return (int) $customer->route_id;
        }

        if (! $gate->distributionOpsEnabled()) {
            return null;
        }

        $distributionSettings = $gate->distributionSettings();
        if (empty($distributionSettings['inherit_customer_route'])) {
            return null;
        }

        return (int) $customer->route_id;
    }
}
