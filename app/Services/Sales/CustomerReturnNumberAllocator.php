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

        $seq = (int) (CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->max('return_seq') ?? 0) + 1;

        while ($this->isSequenceUnavailable($organizationId, $seq)) {
            $seq++;
        }

        return $seq;
    }

    public function formatReturnNo(int $sequence): string
    {
        return 'RET-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Prefer organization-local gaps, and also skip return_no values already used
     * anywhere so leftovers of the global unique index cannot 500 on insert.
     */
    protected function isSequenceUnavailable(int $organizationId, int $seq): bool
    {
        $returnNo = $this->formatReturnNo($seq);

        if (CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->where(function ($query) use ($seq, $returnNo) {
                $query->where('return_seq', $seq)
                    ->orWhere('return_no', $returnNo);
            })
            ->exists()) {
            return true;
        }

        return CustomerReturn::query()
            ->where('return_no', $returnNo)
            ->where('organization_id', '!=', $organizationId)
            ->exists();
    }
}
