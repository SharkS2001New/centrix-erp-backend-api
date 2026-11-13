<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class CustomerReturnNumberAllocator
{
    /** @var array<string, int> Re-entrant GET_LOCK depth per lock key (this instance / connection). */
    protected array $organizationLockDepth = [];

    /**
     * Reserve the next return sequence for an organization.
     * Prefer calling from inside an open DB transaction; also takes a MySQL GET_LOCK
     * so concurrent mobile / POS / fiscal-void creates cannot allocate the same RET-nnnn.
     */
    public function nextForOrganization(int $organizationId): int
    {
        return $this->withOrganizationReturnLock($organizationId, function () use ($organizationId): int {
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            $seq = $this->ceilingForOrganization($organizationId) + 1;

            while ($this->isSequenceUnavailable($organizationId, $seq)) {
                $seq++;
            }

            return $seq;
        });
    }

    public function formatReturnNo(int $sequence): string
    {
        return 'RET-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Highest sequence already claimed for this org (return_seq and RET-nnnn return_no).
     */
    public function ceilingForOrganization(int $organizationId): int
    {
        $maxSeq = (int) (CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->max('return_seq') ?? 0);

        // Guard mismatched historical rows where return_no is RET-1330 but return_seq differs.
        $maxFromNo = (int) (CustomerReturn::query()
            ->where('organization_id', $organizationId)
            ->where('return_no', 'like', 'RET-%')
            ->whereRaw("return_no REGEXP '^RET-[0-9]+$'")
            ->selectRaw('MAX(CAST(SUBSTRING(return_no, 5) AS UNSIGNED)) as m')
            ->value('m') ?? 0);

        return max($maxSeq, $maxFromNo);
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

    /**
     * Serialize return-number allocation across app servers / nested transactions.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withOrganizationReturnLock(int $organizationId, callable $callback): mixed
    {
        $lockKey = 'customer_return_no:'.$organizationId;
        $depth = $this->organizationLockDepth[$lockKey] ?? 0;

        if ($depth > 0) {
            $this->organizationLockDepth[$lockKey] = $depth + 1;
            try {
                return $callback();
            } finally {
                $this->organizationLockDepth[$lockKey] = max(0, ($this->organizationLockDepth[$lockKey] ?? 1) - 1);
            }
        }

        $run = function () use ($organizationId, $lockKey, $callback) {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockKey]);
            if (! $lock || (int) ($lock->acquired ?? 0) !== 1) {
                throw new \RuntimeException(
                    "Could not allocate a return number for organization {$organizationId}. Please try again.",
                );
            }

            $this->organizationLockDepth[$lockKey] = 1;
            try {
                return $callback();
            } finally {
                $this->organizationLockDepth[$lockKey] = 0;
                DB::select('SELECT RELEASE_LOCK(?)', [$lockKey]);
            }
        };

        // When already inside a caller transaction, do not open another — keep locks nested.
        if (DB::transactionLevel() > 0) {
            return $run();
        }

        return DB::transaction($run);
    }
}
