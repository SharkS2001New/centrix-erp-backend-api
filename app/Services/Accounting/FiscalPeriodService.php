<?php

namespace App\Services\Accounting;

use App\Models\FiscalPeriod;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalPeriodService
{
    public function assertDateIsOpen(int $orgId, string $entryDate): void
    {
        $period = $this->findForDate($orgId, $entryDate);
        if ($period && $period->status === 'closed') {
            throw ValidationException::withMessages([
                'entry_date' => [
                    sprintf('Fiscal period "%s" is closed. Choose an open period or reopen it.', $period->period_name),
                ],
            ]);
        }
    }

    public function findForDate(int $orgId, string $entryDate): ?FiscalPeriod
    {
        return FiscalPeriod::query()
            ->where('organization_id', $orgId)
            ->where('start_date', '<=', $entryDate)
            ->where('end_date', '>=', $entryDate)
            ->first();
    }

    /** @return Collection<int, FiscalPeriod> */
    public function listForOrganization(int $orgId, ?int $year = null): Collection
    {
        $query = FiscalPeriod::query()
            ->where('organization_id', $orgId)
            ->orderBy('start_date');

        if ($year !== null) {
            $query->whereYear('start_date', $year);
        }

        return $query->get();
    }

    /** Seed monthly periods for a calendar year (idempotent). */
    public function seedYear(int $orgId, int $year): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1)->toDateString();
            $end = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();
            $name = Carbon::create($year, $month, 1)->format('F Y');

            FiscalPeriod::firstOrCreate(
                [
                    'organization_id' => $orgId,
                    'start_date' => $start,
                ],
                [
                    'period_name' => $name,
                    'end_date' => $end,
                    'status' => 'open',
                ],
            );
        }
    }

    public function close(FiscalPeriod $period, User $user): FiscalPeriod
    {
        if ($period->status === 'closed') {
            throw ValidationException::withMessages([
                'period' => ['This fiscal period is already closed.'],
            ]);
        }

        $period->forceFill([
            'status' => 'closed',
            'closed_at' => now(),
            'closed_by' => $user->id,
        ])->save();

        return $period->fresh();
    }

    public function reopen(FiscalPeriod $period): FiscalPeriod
    {
        if ($period->status === 'open') {
            throw ValidationException::withMessages([
                'period' => ['This fiscal period is already open.'],
            ]);
        }

        $period->forceFill([
            'status' => 'open',
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        return $period->fresh();
    }
}
