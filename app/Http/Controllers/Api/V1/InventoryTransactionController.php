<?php
namespace App\Http\Controllers\Api\V1;

use App\Models\InventoryTransaction;
use Carbon\Carbon;
use Illuminate\Http\Request;

class InventoryTransactionController extends BaseResourceController
{
    public const DEFAULT_RANGE_DAYS = 30;

    protected function modelClass(): string
    {
        return InventoryTransaction::class;
    }

    protected function baseQuery(Request $request)
    {
        return parent::baseQuery($request)
            ->with(['product:product_code,product_name,unit_id']);
    }

    /** @param  \Illuminate\Database\Eloquent\Builder<mixed>  $query */
    protected function applyCreatedAtDateRange($query, Request $request): void
    {
        $hasFrom = $request->filled('from_date');
        $hasTo = $request->filled('to_date');
        $hasExactLookup = $request->filled('q')
            || $request->filled('filter.reference_id')
            || $request->filled('filter.product_code');

        if (! $hasFrom && ! $hasTo && ! $hasExactLookup) {
            $to = now()->toDateString();
            $from = Carbon::parse($to)->subDays(self::DEFAULT_RANGE_DAYS - 1)->toDateString();
            $query->whereDate('created_at', '>=', $from)
                ->whereDate('created_at', '<=', $to);

            return;
        }

        parent::applyCreatedAtDateRange($query, $request);
    }
}
