<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\User;
use App\Services\Erp\CapabilityGate;
use Illuminate\Validation\ValidationException;

/** Distribution organizations only manage route customers — unless External POS /
 *  direct-checkout credit is enabled, in which case shop debtors coexist.
 */
class CustomerRoutePolicy
{
    public function routeCustomersOnly(CapabilityGate $gate): bool
    {
        if (! $gate->enabled('distribution')) {
            return false;
        }

        if ($gate->enabled('sales.pos')) {
            return false;
        }

        $sales = $gate->moduleSettings('sales');
        if (is_array($sales) && ! empty($sales['enable_credit_payment'])) {
            return false;
        }

        return true;
    }

    public function routeCustomersOnlyForUser(User $user): bool
    {
        return $this->routeCustomersOnly(
            app(CapabilityGate::class)->forOrganization($user->organization),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyDistributionCustomerRules(
        array $data,
        CapabilityGate $gate,
        ?Customer $existing = null,
    ): array {
        if (! $this->routeCustomersOnly($gate)) {
            return $data;
        }

        $data['customer_type'] = 'route';

        $routeId = $data['route_id'] ?? $existing?->route_id ?? null;
        if ($routeId === null || $routeId === '' || (int) $routeId <= 0) {
            throw ValidationException::withMessages([
                'route_id' => ['Route is required for customers in a distribution organization.'],
            ]);
        }

        $data['route_id'] = (int) $routeId;

        return $data;
    }
}
