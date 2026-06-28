<?php

namespace App\Services\Customers;

use App\Models\Customer;

class CustomerNumberAllocator
{
    public function nextForOrganization(int $organizationId): int
    {
        $max = Customer::query()
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->max('customer_num');

        return (int) ($max ?? 0) + 1;
    }
}
