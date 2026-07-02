<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Services\Erp\CapabilityGate;

class SaleRouteResolver
{
    public function resolveFromCustomer(
        ?int $customerNum,
        CapabilityGate $gate,
        string $channel = 'backend',
        ?int $explicitRouteId = null,
    ): ?int {
        if ($explicitRouteId) {
            return (int) $explicitRouteId;
        }

        if (! $customerNum) {
            return null;
        }

        $customer = Customer::query()->where('customer_num', $customerNum)->first();
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
