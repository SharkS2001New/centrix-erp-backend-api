<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LegacyOrderService
{
    public function __construct(
        protected CustomerReturnService $customerReturns,
    ) {}

    public function baseQuery(User $user): Builder
    {
        $query = Sale::query()
            ->with(['customer', 'cashier:id,username,full_name', 'items.product.unit'])
            ->where('organization_id', $user->organization_id)
            ->where('fulfillment_meta->legacy_import', true);

        app(UserAccessService::class)->scopeBranchIfLimited($query, $user, 'branch_id');

        return $query;
    }

    public function paginate(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = $this->baseQuery($user);

        if (! empty($filters['from_date'])) {
            $query->whereDate(DB::raw('COALESCE(completed_at, created_at)'), '>=', $filters['from_date']);
        }

        if (! empty($filters['to_date'])) {
            $query->whereDate(DB::raw('COALESCE(completed_at, created_at)'), '<=', $filters['to_date']);
        }

        if (isset($filters['min_order_total']) && $filters['min_order_total'] !== '') {
            $query->where('order_total', '>=', (float) $filters['min_order_total']);
        }

        if (isset($filters['max_order_total']) && $filters['max_order_total'] !== '') {
            $query->where('order_total', '<=', (float) $filters['max_order_total']);
        }

        if (! empty($filters['customer_name'])) {
            $name = trim((string) $filters['customer_name']);
            $query->where(function (Builder $inner) use ($name) {
                $inner->where('customer_name_override', 'like', "%{$name}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('customer_name', 'like', "%{$name}%"));
            });
        }

        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function (Builder $inner) use ($q) {
                $inner->where('order_num', 'like', "%{$q}%")
                    ->orWhere('customer_name_override', 'like', "%{$q}%")
                    ->orWhere('fulfillment_meta->legacy_order_label', 'like', "%{$q}%")
                    ->orWhere('fulfillment_meta->legacy_order_num', 'like', "%{$q}%");
            });
        }

        if (! empty($filters['has_returns'])) {
            $hasReturns = filter_var($filters['has_returns'], FILTER_VALIDATE_BOOLEAN);
            $saleIdsWithReturns = CustomerReturn::query()
                ->where('organization_id', $user->organization_id)
                ->where('return_kind', 'legacy')
                ->where('status', 'approved')
                ->whereNotNull('sale_id')
                ->distinct()
                ->pluck('sale_id');

            if ($hasReturns) {
                $query->whereIn('id', $saleIdsWithReturns);
            } else {
                $query->whereNotIn('id', $saleIdsWithReturns);
            }
        }

        $perPage = min((int) ($filters['per_page'] ?? 25), 200);

        $paginator = $query->orderByDesc('completed_at')->orderByDesc('id')->paginate($perPage);
        $this->attachReturnSummaries($paginator->getCollection(), $user);

        return $paginator;
    }

    public function findForUser(User $user, int $saleId): Sale
    {
        $sale = $this->baseQuery($user)->findOrFail($saleId);
        $this->attachReturnSummaries(collect([$sale]), $user);

        return $sale;
    }

    public function deleteForUser(User $user, int $saleId): void
    {
        $sale = $this->baseQuery($user)->findOrFail($saleId);

        if (! $sale->isLegacyImport()) {
            throw ValidationException::withMessages([
                'sale' => ['Only materialized legacy orders can be deleted.'],
            ]);
        }

        $legacyReturns = CustomerReturn::query()
            ->where('organization_id', $user->organization_id)
            ->where('sale_id', $sale->id)
            ->where('return_kind', 'legacy')
            ->with('lines')
            ->get();

        if ($legacyReturns->isNotEmpty() && ! $user->is_admin) {
            throw ValidationException::withMessages([
                'sale' => ['Only an organization admin can delete a legacy order that has returns linked to it.'],
            ]);
        }

        if (DB::table('customer_invoices')->where('sale_id', $sale->id)->exists()) {
            throw ValidationException::withMessages([
                'sale' => ['Cannot delete a legacy order that has customer invoices.'],
            ]);
        }

        if (DB::table('dispatch_trip_sales')->where('sale_id', $sale->id)->exists()) {
            throw ValidationException::withMessages([
                'sale' => ['Cannot delete a legacy order assigned to a dispatch trip.'],
            ]);
        }

        DB::transaction(function () use ($sale, $legacyReturns, $user) {
            foreach ($legacyReturns as $return) {
                $this->customerReturns->deleteReturn($return, $user);
            }

            KraResponse::query()->where('sale_id', $sale->id)->delete();
            $sale->payments()->delete();
            $sale->items()->delete();
            $sale->delete();
        });
    }

    protected function attachReturnSummaries($sales, User $user): void
    {
        $organizationId = (int) $user->organization_id;
        $saleIds = $sales->pluck('id')->filter()->all();
        if ($saleIds === []) {
            return;
        }

        $summaries = CustomerReturn::query()
            ->selectRaw("sale_id, COUNT(*) as return_count_all, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as return_count, COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) as returned_total")
            ->where('organization_id', $organizationId)
            ->where('return_kind', 'legacy')
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id')
            ->get()
            ->keyBy('sale_id');

        foreach ($sales as $sale) {
            $summary = $summaries->get($sale->id);
            $returnedTotal = round((float) ($summary->returned_total ?? 0), 2);
            $orderTotal = round((float) ($sale->order_total ?? 0), 2);
            $returnCount = (int) ($summary->return_count ?? 0);
            $returnCountAll = (int) ($summary->return_count_all ?? 0);

            $sale->setAttribute('legacy_return_summary', [
                'return_count' => $returnCount,
                'return_count_all' => $returnCountAll,
                'returned_total' => $returnedTotal,
                'has_returns' => $returnCount > 0,
                'fully_returned' => $returnCount > 0 && $orderTotal > 0 && $returnedTotal >= $orderTotal,
                'can_delete' => $returnCountAll === 0,
                'can_admin_delete' => (bool) $user->is_admin,
            ]);
        }
    }
}
