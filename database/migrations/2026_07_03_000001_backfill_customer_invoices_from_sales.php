<?php

use App\Models\Sale;
use App\Models\User;
use App\Services\Accounting\CustomerInvoiceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_invoices') || ! Schema::hasTable('sales')) {
            return;
        }

        $service = app(CustomerInvoiceService::class);

        Sale::query()
            ->whereNotNull('customer_num')
            ->where('order_total', '>', 0)
            ->whereNull('deleted_at')
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')
                    ->from('customer_invoices as ci')
                    ->whereColumn('ci.sale_id', 'sales.id')
                    ->whereNull('ci.deleted_at');
            })
            ->orderBy('id')
            ->chunkById(100, function ($sales) use ($service) {
                foreach ($sales as $sale) {
                    $userId = $sale->cashier_id ?? $sale->created_by;
                    if (! $userId) {
                        continue;
                    }

                    $user = User::query()->find($userId);
                    if (! $user) {
                        continue;
                    }

                    $service->ensureForSale($sale, $user);
                }
            });
    }

    public function down(): void
    {
        // Non-destructive backfill only.
    }
};
