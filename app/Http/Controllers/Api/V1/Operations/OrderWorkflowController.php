<?php

namespace App\Http\Controllers\Api\V1\Operations;

use App\Http\Controllers\Api\V1\Operations\Concerns\HandlesInventory;
use App\Http\Controllers\Controller;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Erp\ErpContext;
use Illuminate\Http\Request;
use InvalidArgumentException;

class OrderWorkflowController extends Controller
{
    use HandlesInventory;

    /** @var array<string, list<string>> */
    protected array $transitions = [
        'booked' => ['pending', 'cancelled'],
        'pending' => ['pending_payment', 'processed', 'cancelled'],
        'pending_payment' => ['paid', 'partial', 'cancelled'],
        'paid' => ['processed', 'completed'],
        'processed' => ['completed'],
        'draft' => ['held', 'completed', 'booked', 'cancelled'],
        'held' => ['draft', 'completed', 'cancelled'],
    ];

    public function __construct(protected ErpContext $erp) {}

    public function transition(Request $request, int $saleId)
    {
        $data = $request->validate([
            'status' => 'required|string',
            'fulfillment_meta' => 'nullable|array',
        ]);

        $sale = Sale::findOrFail($saleId);
        $updated = $this->transitionSale(
            $sale,
            $data['status'],
            $request->user(),
            $data['fulfillment_meta'] ?? []
        );

        return response()->json($updated);
    }

    protected function transitionSale(Sale $sale, string $toStatus, User $user, array $meta = []): Sale
    {
        $from = $sale->status;
        $allowed = $this->transitions[$from] ?? [];
        if (! in_array($toStatus, $allowed, true) && $toStatus !== 'cancelled') {
            throw new InvalidArgumentException("Cannot transition from [{$from}] to [{$toStatus}].");
        }

        if ($toStatus === 'cancelled') {
            $sale->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
            ]);

            return $sale;
        }

        $updates = ['status' => $toStatus];

        if ($toStatus === 'processed' || $toStatus === 'completed') {
            $meta = array_merge($sale->fulfillment_meta ?? [], $meta);

            if (! empty($meta['driver_id'])) {
                $driver = \App\Models\Driver::find($meta['driver_id']);
                if ($driver) {
                    if (empty($meta['vehicle_id']) && $driver->default_vehicle_id) {
                        $meta['vehicle_id'] = $driver->default_vehicle_id;
                    }
                    if (! $sale->route_id && $driver->default_route_id) {
                        $updates['route_id'] = $driver->default_route_id;
                    }
                }
            }

            $updates['fulfillment_meta'] = $meta;
        }

        if ($toStatus === 'completed') {
            $updates['completed_at'] = now();
            $this->deductSaleStockIfNeeded($sale, $user);
        }

        $sale->update($updates);

        return $sale->fresh();
    }

    protected function deductSaleStockIfNeeded(Sale $sale, User $user): void
    {
        if ($sale->stock_balanced) {
            return;
        }

        $inventorySettings = $this->erp->gateForUser($user)->moduleSettings('inventory');
        $salesSettings = $this->erp->gateForUser($user)->moduleSettings('sales');
        $txnType = $this->saleTransactionType($sale->channel);
        $allowBelowStock = $this->organizationAllowsBelowStock($user->organization_id);

        foreach ($sale->items ?? SaleItem::where('sale_id', $sale->id)->get() as $item) {
            $isRetailLine = (bool) $item->on_wholesale_retail;
            $location = $this->saleLineStockLocation(
                $sale->channel,
                $inventorySettings,
                $salesSettings,
                $isRetailLine,
            );

            $this->postStockLedger([
                'branch_id' => $sale->branch_id,
                'product_code' => $item->product_code,
                'stock_location' => $location,
                'transaction_type' => $txnType,
                'reference_type' => 'sale',
                'reference_id' => $sale->id,
                'quantity_change' => -abs((float) $item->quantity),
                'created_by' => $user->id,
            ], $allowBelowStock);
        }

        $sale->update(['stock_balanced' => 1]);
    }
}
