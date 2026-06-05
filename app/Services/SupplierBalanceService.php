<?php

namespace App\Services;

use App\Models\LpoMst;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;

class SupplierBalanceService
{
    /**
     * Amount owing = opening balance + LPO payables − payments posted.
     */
    public function recalculate(int $supplierId): Supplier
    {
        $supplier = Supplier::query()->whereNull('deleted_at')->findOrFail($supplierId);

        $lpoTotal = (float) LpoMst::query()
            ->where('supplier_id', $supplierId)
            ->whereNull('deleted_at')
            ->sum(DB::raw('COALESCE(NULLIF(net_amount, 0), total_amount, 0)'));

        $paidTotal = (float) SupplierPayment::query()
            ->where('supplier_id', $supplierId)
            ->sum('amount_paid');

        $opening = (float) ($supplier->opening_balance ?? 0);
        $owing = max(0, round($opening + $lpoTotal - $paidTotal, 2));

        $supplier->update(['current_balance' => $owing]);

        return $supplier->fresh();
    }
}
