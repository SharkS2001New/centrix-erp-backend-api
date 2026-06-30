<?php

namespace App\Services\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;

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

    /**
     * Reserve the first customer number for a bulk import (single row lock).
     */
    public function reserveSequenceStart(int $organizationId): int
    {
        return DB::transaction(fn () => $this->nextForOrganization($organizationId));
    }
}
