<?php

namespace App\Services\Sales;

use App\Models\MobileRouteExpense;
use App\Models\Organization;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Services\OrganizationPlatformConfigService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class MobileRouteExpenseService
{
    public function __construct(
        protected OrganizationPlatformConfigService $platformConfig,
        protected UserAccessService $access,
    ) {}

    public function isEnabledForOrganization(Organization $organization): bool
    {
        $config = $this->platformConfig->salesPlatformConfigForOrganization($organization);
        if (! ($config['enable_mobile_orders'] ?? true)) {
            return false;
        }

        return (bool) ($config['enable_mobile_orders_expenses_card'] ?? false);
    }

    public function assertEnabledForUser(User $user): void
    {
        $org = $user->organization ?? Organization::query()->find($user->organization_id);
        if (! $org || ! $this->isEnabledForOrganization($org)) {
            throw ValidationException::withMessages([
                'feature' => ['Mobile route expenses are not enabled for this organization.'],
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForRep(User $user, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->assertEnabledForUser($user);

        $query = MobileRouteExpense::query()
            ->with(['user:id,username,full_name', 'approvedByUser:id,username,full_name'])
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($fromDate) {
            $query->whereDate('expense_date', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('expense_date', '<=', $toDate);
        }

        return $query->limit(200)->get()->map(fn (MobileRouteExpense $row) => $this->serialize($row))->all();
    }

    /**
     * @param  array{description: string, expense_amount: float|int|string, expense_date?: string}  $data
     * @return array<string, mixed>
     */
    public function createForRep(User $user, array $data): array
    {
        $this->assertEnabledForUser($user);

        $amount = round((float) ($data['expense_amount'] ?? 0), 2);
        if ($amount < 0.01) {
            throw ValidationException::withMessages([
                'expense_amount' => ['Enter an expense amount greater than zero.'],
            ]);
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '') {
            throw ValidationException::withMessages([
                'description' => ['Describe this expense.'],
            ]);
        }

        $date = isset($data['expense_date']) && $data['expense_date']
            ? Carbon::parse($data['expense_date'])->startOfDay()
            : now()->startOfDay();
        if ($date->gt(now()->startOfDay())) {
            throw ValidationException::withMessages([
                'expense_date' => ['Expense date cannot be in the future.'],
            ]);
        }

        $expense = MobileRouteExpense::create([
            'organization_id' => (int) $user->organization_id,
            'branch_id' => $user->branch_id ? (int) $user->branch_id : null,
            'user_id' => (int) $user->id,
            'expense_date' => $date->toDateString(),
            'description' => mb_substr($description, 0, 200),
            'expense_amount' => $amount,
            'status' => MobileRouteExpense::STATUS_PENDING,
        ]);

        return $this->serialize($expense->load(['user:id,username,full_name']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForManager(User $user): array
    {
        $this->assertEnabledForUser($user);

        $query = $this->managerQuery($user)
            ->with(['user:id,username,full_name', 'approvedByUser:id,username,full_name'])
            ->where('status', MobileRouteExpense::STATUS_PENDING)
            ->orderByDesc('id');

        return $query->limit(200)->get()->map(fn (MobileRouteExpense $row) => $this->serialize($row))->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function performedForManager(User $user, ?string $fromDate = null, ?string $toDate = null): array
    {
        $this->assertEnabledForUser($user);

        $query = $this->managerQuery($user)
            ->with(['user:id,username,full_name', 'approvedByUser:id,username,full_name'])
            ->where('status', MobileRouteExpense::STATUS_APPROVED)
            ->orderByDesc('expense_date')
            ->orderByDesc('id');

        if ($fromDate || $toDate) {
            $query->where(function (Builder $inner) use ($fromDate, $toDate) {
                if ($fromDate) {
                    $inner->whereDate('expense_date', '>=', $fromDate);
                }
                if ($toDate) {
                    $inner->whereDate('expense_date', '<=', $toDate);
                }
            });
        }

        return $query->limit(200)->get()->map(fn (MobileRouteExpense $row) => $this->serialize($row))->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array{approved_count: int, data: list<array<string, mixed>>, errors: list<array{id: int, message: string}>}
     */
    public function approveMany(User $manager, array $ids): array
    {
        $this->assertEnabledForUser($manager);

        $approved = [];
        $errors = [];

        foreach ($ids as $id) {
            $query = $this->managerQuery($manager)
                ->where('id', (int) $id)
                ->where('status', MobileRouteExpense::STATUS_PENDING);
            $expense = $query->first();

            if (! $expense) {
                $errors[] = ['id' => (int) $id, 'message' => 'Expense not found or not pending.'];
                continue;
            }

            $expense->status = MobileRouteExpense::STATUS_APPROVED;
            $expense->approved_by = (int) $manager->id;
            $expense->approved_at = now();
            $expense->rejected_by = null;
            $expense->rejected_at = null;
            $expense->reject_reason = null;
            $expense->save();

            $this->invalidateRepSales($expense);
            $approved[] = $this->serialize(
                $expense->load(['user:id,username,full_name', 'approvedByUser:id,username,full_name']),
            );
        }

        return [
            'approved_count' => count($approved),
            'data' => $approved,
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array{rejected_count: int, data: list<array<string, mixed>>, errors: list<array{id: int, message: string}>}
     */
    public function rejectMany(User $manager, array $ids, ?string $reason = null): array
    {
        $this->assertEnabledForUser($manager);

        $rejected = [];
        $errors = [];
        $trimmedReason = $reason !== null ? mb_substr(trim($reason), 0, 200) : null;

        foreach ($ids as $id) {
            $query = $this->managerQuery($manager)
                ->where('id', (int) $id)
                ->where('status', MobileRouteExpense::STATUS_PENDING);
            $expense = $query->first();

            if (! $expense) {
                $errors[] = ['id' => (int) $id, 'message' => 'Expense not found or not pending.'];
                continue;
            }

            $expense->status = MobileRouteExpense::STATUS_REJECTED;
            $expense->rejected_by = (int) $manager->id;
            $expense->rejected_at = now();
            $expense->reject_reason = $trimmedReason ?: null;
            $expense->save();

            $rejected[] = $this->serialize(
                $expense->load(['user:id,username,full_name']),
            );
        }

        return [
            'rejected_count' => count($rejected),
            'data' => $rejected,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, float> expense_date => total
     */
    public function approvedTotalsByDayForUser(User $user, Carbon $from, Carbon $to): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return MobileRouteExpense::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('status', MobileRouteExpense::STATUS_APPROVED)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->selectRaw('DATE(expense_date) as expense_day')
            ->selectRaw('COALESCE(ROUND(SUM(expense_amount), 2), 0) as total_amount')
            ->groupBy('expense_day')
            ->pluck('total_amount', 'expense_day')
            ->map(fn ($value) => (float) $value)
            ->all();
    }

    public function approvedTotalForUserBetween(User $user, Carbon $from, Carbon $to): float
    {
        if (! $this->tableReady()) {
            return 0.0;
        }

        return round((float) MobileRouteExpense::query()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('status', MobileRouteExpense::STATUS_APPROVED)
            ->whereDate('expense_date', '>=', $from->toDateString())
            ->whereDate('expense_date', '<=', $to->toDateString())
            ->sum('expense_amount'), 2);
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(MobileRouteExpense $expense): array
    {
        $user = $expense->user;

        return [
            'id' => (int) $expense->id,
            'organization_id' => (int) $expense->organization_id,
            'branch_id' => $expense->branch_id !== null ? (int) $expense->branch_id : null,
            'user_id' => (int) $expense->user_id,
            'expense_date' => optional($expense->expense_date)?->toDateString(),
            'description' => (string) $expense->description,
            'expense_amount' => round((float) $expense->expense_amount, 2),
            'status' => (string) $expense->status,
            'approved_by' => $expense->approved_by !== null ? (int) $expense->approved_by : null,
            'approved_at' => optional($expense->approved_at)?->toIso8601String(),
            'rejected_by' => $expense->rejected_by !== null ? (int) $expense->rejected_by : null,
            'rejected_at' => optional($expense->rejected_at)?->toIso8601String(),
            'reject_reason' => $expense->reject_reason,
            'created_at' => optional($expense->created_at)?->toIso8601String(),
            'user' => $user ? [
                'id' => (int) $user->id,
                'username' => $user->username,
                'full_name' => $user->full_name ?: $user->username,
            ] : null,
            'approved_by_user' => $expense->approvedByUser ? [
                'id' => (int) $expense->approvedByUser->id,
                'username' => $expense->approvedByUser->username,
                'full_name' => $expense->approvedByUser->full_name ?: $expense->approvedByUser->username,
            ] : null,
        ];
    }

    protected function managerQuery(User $user): Builder
    {
        $query = MobileRouteExpense::query()
            ->where('organization_id', $user->organization_id);
        $this->access->scopeBranchIfLimited($query, $user);

        return $query;
    }

    protected function invalidateRepSales(MobileRouteExpense $expense): void
    {
        $rep = $expense->user ?? User::query()->find($expense->user_id);
        if (! $rep) {
            return;
        }

        $date = $expense->expense_date
            ? Carbon::parse($expense->expense_date)->startOfDay()
            : now()->startOfDay();
        app(MobileSalesService::class)->invalidateDashboardForUser($rep, $date);
    }

    protected function tableReady(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('mobile_route_expenses');
    }
}
