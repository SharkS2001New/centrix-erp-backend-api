<?php

namespace App\Models\Concerns;

use App\Models\Customer;
use App\Models\Relations\BelongsToCustomerByOrganization;

trait BelongsToOrganizationCustomer
{
    public function customer(): BelongsToCustomerByOrganization
    {
        $instance = $this->newRelatedInstance(Customer::class);

        return new BelongsToCustomerByOrganization(
            $instance->newQuery(),
            $this,
            'customer_num',
            'customer_num',
            'customer'
        );
    }
}
