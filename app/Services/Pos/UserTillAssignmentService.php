<?php

namespace App\Services\Pos;

use App\Models\Till;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserTillAssignmentService
{
    /**
     * Sync tills.cashier_id for a user.
     *
     * @param  int|string|null  $tillId  Till id, "auto"/"new" to pick/create free Till01–Till10, or null/'' to clear.
     */
    public function sync(User $user, int|string|null $tillId): ?Till
    {
        return DB::transaction(function () use ($user, $tillId) {
            if ($tillId === null || $tillId === '') {
                $this->clearAssignment((int) $user->id);

                return null;
            }

            if (is_string($tillId) && in_array(strtolower(trim($tillId)), ['auto', 'new'], true)) {
                return $this->assignAutoTill($user);
            }

            return $this->assignTill($user, (int) $tillId);
        });
    }

    public function assignedTillId(int $userId): ?int
    {
        $id = Till::query()->where('cashier_id', $userId)->value('id');

        return $id ? (int) $id : null;
    }

    public function clearAssignment(int $userId): void
    {
        Till::query()
            ->where('cashier_id', $userId)
            ->update(['cashier_id' => null]);
    }

    public function assignTill(User $user, int $tillId): Till
    {
        $till = Till::query()->find($tillId);
        if (! $till) {
            throw ValidationException::withMessages([
                'till_id' => ['Selected till was not found.'],
            ]);
        }

        if ((int) $till->organization_id !== (int) $user->organization_id) {
            throw ValidationException::withMessages([
                'till_id' => ['Till must belong to the same organization as the user.'],
            ]);
        }

        if ($user->branch_id && (int) $till->branch_id !== (int) $user->branch_id) {
            throw ValidationException::withMessages([
                'till_id' => ['Till must belong to the user\'s branch.'],
            ]);
        }

        // Locked to another cashier — never reassign via auto or accidental pick.
        if ($till->cashier_id && (int) $till->cashier_id !== (int) $user->id) {
            throw ValidationException::withMessages([
                'till_id' => ['That till is locked to another cashier and cannot be assigned.'],
            ]);
        }

        $this->clearAssignment((int) $user->id);

        $till->update(['cashier_id' => $user->id]);

        return $till->fresh();
    }

    /**
     * On login / declare float when the cashier has no till:
     * use their locked till if any, else the lowest free Till01–Till10, else create the next slot.
     * Never takes a till locked to someone else.
     */
    public function assignAutoTill(User $user): Till
    {
        $branchId = (int) ($user->branch_id ?? 0);
        if ($branchId <= 0) {
            throw ValidationException::withMessages([
                'till_id' => ['Set the user\'s branch before assigning a till.'],
            ]);
        }

        $existing = Till::query()
            ->where('cashier_id', $user->id)
            ->where('branch_id', $branchId)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Free = unlocked (cashier_id null). Locked tills are skipped.
        $freeRows = Till::query()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->whereNull('cashier_id')
            ->get()
            ->sortBy(fn (Till $till) => TillNumbering::sortKey($till))
            ->values();

        $free = $freeRows->first();
        if ($free) {
            $free->update(['cashier_id' => $user->id]);

            return $free->fresh();
        }

        $allBranchTills = Till::query()
            ->where('branch_id', $branchId)
            ->get(['till_number', 'till_name']);

        $label = TillNumbering::nextLabelOrFail($allBranchTills);

        return Till::create([
            'organization_id' => $user->organization_id,
            'branch_id' => $branchId,
            'till_number' => $label,
            'till_name' => $label,
            'is_active' => true,
            'cashier_id' => $user->id,
        ]);
    }
}
