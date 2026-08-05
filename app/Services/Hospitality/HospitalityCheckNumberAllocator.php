<?php

namespace App\Services\Hospitality;

use App\Models\HospitalityCheck;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sequential digit check numbers for Hotel POS, with offline reserve blocks.
 */
class HospitalityCheckNumberAllocator
{
    public const MAX_RESERVE_BLOCK = 50;

    public function allocateOne(int $organizationId): string
    {
        $block = $this->reserveBlockForOrganization($organizationId, 1);

        return (string) $block['start'];
    }

    /**
     * @return array{start: int, end: int, numbers: list<int>}
     */
    public function reserveBlockForOrganization(int $organizationId, int $count): array
    {
        $count = max(1, min(self::MAX_RESERVE_BLOCK, $count));

        return $this->withOrganizationLock($organizationId, function () use ($organizationId, $count): array {
            Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            $this->lockWatermarkRow($organizationId);

            $start = $this->ceilingForOrganization($organizationId) + 1;
            $end = $start + $count - 1;
            $this->writeWatermark($organizationId, $end);

            $numbers = [];
            for ($n = $start; $n <= $end; $n++) {
                $numbers[] = $n;
            }

            return [
                'start' => $start,
                'end' => $end,
                'numbers' => $numbers,
            ];
        });
    }

    /** Raise watermark so a preferred offline check # is not re-issued. */
    public function claimSpecific(int $organizationId, int $checkNum): void
    {
        if ($checkNum <= 0) {
            return;
        }

        $this->withOrganizationLock($organizationId, function () use ($organizationId, $checkNum): void {
            Organization::query()
                ->whereKey($organizationId)
                ->lockForUpdate()
                ->first();

            $this->lockWatermarkRow($organizationId);
            $this->writeWatermark($organizationId, $checkNum);
        });
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    protected function withOrganizationLock(int $organizationId, callable $callback): mixed
    {
        $lockKey = 'hosp_check_num:'.$organizationId;

        return DB::transaction(function () use ($lockKey, $callback) {
            $lock = DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockKey]);
            if (! $lock || (int) ($lock->acquired ?? 0) !== 1) {
                throw new \RuntimeException('Could not allocate a hospitality check number. Please try again.');
            }

            try {
                return $callback();
            } finally {
                DB::select('SELECT RELEASE_LOCK(?)', [$lockKey]);
            }
        });
    }

    protected function ceilingForOrganization(int $organizationId): int
    {
        $numbers = HospitalityCheck::query()
            ->where('organization_id', $organizationId)
            ->pluck('check_number');

        $maxSeq = 0;
        foreach ($numbers as $number) {
            if (! is_string($number) || $number === '' || ! ctype_digit($number)) {
                continue;
            }
            $maxSeq = max($maxSeq, (int) $number);
        }

        return max($maxSeq, $this->readWatermark($organizationId));
    }

    protected function readWatermark(int $organizationId): int
    {
        if (! $this->watermarkTableReady()) {
            return 0;
        }

        return (int) (DB::table('hospitality_check_num_watermarks')
            ->where('organization_id', $organizationId)
            ->value('watermark') ?? 0);
    }

    protected function lockWatermarkRow(int $organizationId): void
    {
        if (! $this->watermarkTableReady()) {
            return;
        }

        $exists = DB::table('hospitality_check_num_watermarks')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->exists();

        if (! $exists) {
            DB::table('hospitality_check_num_watermarks')->insert([
                'organization_id' => $organizationId,
                'watermark' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('hospitality_check_num_watermarks')
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();
        }
    }

    protected function writeWatermark(int $organizationId, int $watermark): void
    {
        if (! $this->watermarkTableReady()) {
            return;
        }

        $current = $this->readWatermark($organizationId);
        if ($watermark <= $current) {
            return;
        }

        DB::table('hospitality_check_num_watermarks')->updateOrInsert(
            ['organization_id' => $organizationId],
            [
                'watermark' => $watermark,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    protected function watermarkTableReady(): bool
    {
        return Schema::hasTable('hospitality_check_num_watermarks');
    }
}
