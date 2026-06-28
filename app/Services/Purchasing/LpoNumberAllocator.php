<?php

namespace App\Services\Purchasing;

use App\Models\LpoMst;

class LpoNumberAllocator
{
    public function nextForOrganization(int $organizationId): int
    {
        $max = LpoMst::query()
            ->where('organization_id', $organizationId)
            ->max('lpo_seq');

        return (int) ($max ?? 0) + 1;
    }
}
