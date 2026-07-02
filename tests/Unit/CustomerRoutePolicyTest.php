<?php

namespace Tests\Unit;

use App\Models\Organization;
use App\Services\Customers\CustomerRoutePolicy;
use App\Services\Erp\CapabilityGate;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerRoutePolicyTest extends TestCase
{
    public function test_distribution_organization_requires_route_customers(): void
    {
        $org = new Organization([
            'enabled_modules' => ['distribution' => true, 'customers_suppliers' => true],
        ]);
        $gate = (new CapabilityGate($org))->forOrganization($org);
        $policy = app(CustomerRoutePolicy::class);

        $this->assertTrue($policy->routeCustomersOnly($gate));

        try {
            $policy->applyDistributionCustomerRules(['customer_name' => 'Test Shop'], $gate);
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('route_id', $e->errors());
        }

        $normalized = $policy->applyDistributionCustomerRules([
            'customer_name' => 'Test Shop',
            'customer_type' => 'debtor',
            'route_id' => 5,
        ], $gate);

        $this->assertSame('route', $normalized['customer_type']);
        $this->assertSame(5, $normalized['route_id']);
    }

    public function test_non_distribution_organization_allows_debtor_customers(): void
    {
        $org = new Organization([
            'enabled_modules' => ['customers_suppliers' => true],
        ]);
        $gate = (new CapabilityGate($org))->forOrganization($org);
        $policy = app(CustomerRoutePolicy::class);

        $this->assertFalse($policy->routeCustomersOnly($gate));

        $normalized = $policy->applyDistributionCustomerRules([
            'customer_type' => 'debtor',
            'route_id' => null,
        ], $gate);

        $this->assertSame('debtor', $normalized['customer_type']);
    }
}
