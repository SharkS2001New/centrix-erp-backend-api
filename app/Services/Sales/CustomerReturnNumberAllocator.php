<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\Organization;

class CustomerReturnNumberAllocator
{
    /**
     * Reserve the next return sequence for an organization.
     * Must run inside an open database transaction.
     */
    public function nextForOrganization(int $organizationId): int
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

        $max = CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->max('return_seq');

        return (int) ($max ?? 0) + 1;
    }

    public function formatReturnNo(int $sequence): string
    {
        return 'RET-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
