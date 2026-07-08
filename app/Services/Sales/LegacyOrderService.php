<?php

namespace App\Services\Sales;

use App\Models\CustomerReturn;
use App\Models\CustomerReturnLine;
use App\Models\KraResponse;
use App\Models\Sale;
use App\Models\User;
use App\Services\Auth\UserAccessService;
use App\Support\EffectiveSaleDate;
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

        EffectiveSaleDate::applyFromToDateFilter(
            $query,
            ! empty($filters['from_date']) ? (string) $filters['from_date'] : null,
            ! empty($filters['to_date']) ? (string) $filters['to_date'] : null,
            'sales',
        );

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

                $numericQ = str_replace([',', ' '], '', $q);
                if ($numericQ !== '' && is_numeric($numericQ)) {
                    $inner->orWhere('order_total', (float) $numericQ);
                }
            });
        }

        if (isset($filters['order_total']) && $filters['order_total'] !== '') {
            $query->where('order_total', (float) $filters['order_total']);
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

    public function prepareSaleForPrint(Sale $sale): Sale
    {
        if (! $sale->isLegacyImport()) {
            return $sale;
        }

        $sale->loadMissing(['items.product.unit', 'customer']);

        $returnLines = CustomerReturnLine::query()
            ->whereHas('customerReturn', function (Builder $query) use ($sale) {
                $query->where('sale_id', $sale->id)
                    ->where('return_kind', 'legacy')
                    ->where('status', 'approved');
            })
            ->get();

        $bySaleItemId = $returnLines->groupBy('sale_item_id');
        $returnedTotal = 0.0;

        foreach ($sale->items as $item) {
            $lines = $bySaleItemId->get($item->id, collect());
            $returnedQty = (float) $lines->sum(fn (CustomerReturnLine $line) => (float) $line->return_qty);
            $returnedAmount = (float) $lines->sum(fn (CustomerReturnLine $line) => (float) $line->amount);
            if ($returnedQty <= 0 && $returnedAmount <= 0) {
                continue;
            }

            $item->quantity = round((float) $item->quantity + $returnedQty, 4);
            $item->amount = round((float) $item->amount + $returnedAmount, 2);
            $returnedTotal += $returnedAmount;
        }

        $returnedTotal = round($returnedTotal, 2);
        $remainingTotal = round((float) ($sale->order_total ?? 0), 2);
        $originalTotal = $this->legacyOriginalOrderTotal($sale, $returnedTotal);
        $sale->order_total = $originalTotal > 0 ? $originalTotal : round($remainingTotal + $returnedTotal, 2);
        $sale->amount_paid = $sale->order_total;
        $sale->payment_status = 'paid';

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

        $summaries = $this->legacyReturnSummariesForSaleIds($organizationId, $saleIds);

        foreach ($sales as $sale) {
            $summary = $summaries->get($sale->id, $this->emptyLegacyReturnSummary());
            $summary['can_delete'] = ((int) ($summary['return_count_all'] ?? 0)) === 0;
            $summary['can_admin_delete'] = (bool) $user->is_admin;
            $summary['can_create_return'] = ! ($summary['fully_returned'] ?? false)
                && ((int) ($summary['return_count_all'] ?? 0)) === 0;

            $sale->setAttribute('legacy_return_summary', $summary);
        }
    }

    /**
     * @param  list<int>  $saleIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function legacyReturnSummariesForSaleIds(int $organizationId, array $saleIds): \Illuminate\Support\Collection
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));
        if ($saleIds === []) {
            return collect();
        }

        $sales = Sale::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $saleIds)
            ->get(['id', 'order_total', 'fulfillment_meta'])
            ->keyBy('id');

        $returnRows = CustomerReturn::query()
            ->selectRaw("sale_id, COUNT(*) as return_count_all, SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as return_count, COALESCE(SUM(CASE WHEN status = 'approved' THEN total_amount ELSE 0 END), 0) as returned_total, MAX(CASE WHEN status = 'approved' THEN id END) as latest_approved_return_id, MAX(CASE WHEN status = 'approved' THEN return_no END) as latest_approved_return_no")
            ->where('organization_id', $organizationId)
            ->where('return_kind', 'legacy')
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id')
            ->get()
            ->keyBy(fn ($row) => (int) $row->sale_id);

        return collect($saleIds)->mapWithKeys(function (int $saleId) use ($sales, $returnRows) {
            $sale = $sales->get($saleId);
            if (! $sale) {
                return [$saleId => $this->emptyLegacyReturnSummary()];
            }

            return [$saleId => $this->buildLegacyReturnSummary($sale, $returnRows->get($saleId))];
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildLegacyReturnSummary(Sale $sale, mixed $returnRow): array
    {
        if ($returnRow === null) {
            return array_merge($this->emptyLegacyReturnSummary(), [
                'original_order_total' => $this->legacyOriginalOrderTotal($sale, 0.0),
            ]);
        }

        $returnedTotal = round((float) ($returnRow->returned_total ?? 0), 2);
        $remainingTotal = round((float) ($sale->order_total ?? 0), 2);
        $originalTotal = $this->legacyOriginalOrderTotal($sale, $returnedTotal);
        $returnCount = (int) ($returnRow->return_count ?? 0);
        $returnCountAll = (int) ($returnRow->return_count_all ?? 0);
        $fullyReturned = $this->isLegacyFullyReturned(
            $returnCount,
            $returnedTotal,
            $remainingTotal,
            $originalTotal,
        );
        $approvedReturnId = $returnRow->latest_approved_return_id ?? null;

        return [
            'return_count' => $returnCount,
            'return_count_all' => $returnCountAll,
            'returned_total' => $returnedTotal,
            'original_order_total' => $originalTotal,
            'has_returns' => $returnCount > 0,
            'fully_returned' => $fullyReturned,
            'legacy_return_id' => $approvedReturnId !== null && $approvedReturnId !== ''
                ? (int) $approvedReturnId
                : null,
            'legacy_return_no' => $returnRow->latest_approved_return_no ?? null,
        ];
    }

    /** @return array<string, mixed> */
    public function legacyReturnSummaryForSale(Sale $sale): array
    {
        return $this->legacyReturnSummariesForSaleIds(
            (int) $sale->organization_id,
            [(int) $sale->id],
        )->get((int) $sale->id, $this->emptyLegacyReturnSummary());
    }

    /** @return array<string, mixed> */
    public function emptyLegacyReturnSummary(): array
    {
        return [
            'return_count' => 0,
            'return_count_all' => 0,
            'returned_total' => 0.0,
            'original_order_total' => 0.0,
            'has_returns' => false,
            'fully_returned' => false,
            'legacy_return_id' => null,
            'legacy_return_no' => null,
        ];
    }

    protected function legacyOriginalOrderTotal(Sale $sale, float $returnedTotal): float
    {
        $meta = is_array($sale->fulfillment_meta) ? $sale->fulfillment_meta : [];

        if (isset($meta['legacy_order_total']) && is_numeric($meta['legacy_order_total'])) {
            return round((float) $meta['legacy_order_total'], 2);
        }

        $current = round((float) ($sale->order_total ?? 0), 2);
        if ($returnedTotal > 0) {
            return round($returnedTotal + $current, 2);
        }

        return $current;
    }

    protected function isLegacyFullyReturned(
        int $returnCount,
        float $returnedTotal,
        float $remainingTotal,
        float $originalTotal,
    ): bool {
        if ($returnCount <= 0) {
            return false;
        }

        if ($remainingTotal <= 0.01) {
            return true;
        }

        if ($originalTotal <= 0) {
            return false;
        }

        return $returnedTotal >= ($originalTotal - 0.02);
    }
}
