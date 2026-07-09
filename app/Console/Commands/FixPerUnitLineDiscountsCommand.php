<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixPerUnitLineDiscountsCommand extends Command
{
    protected $signature = 'erp:fix-per-unit-line-discounts
                            {--from-date= : Start date (Y-m-d) for sale created_at}
                            {--to-date= : End date (Y-m-d) for sale created_at}
                            {--sale-id=* : Limit to specific sale IDs}
                            {--statuses=booked,pending : Comma-separated sale statuses to include}
                            {--dry-run : Preview changes without writing}
                            {--force : Apply without confirmation prompt}';

    protected $description = 'Recalculate line discounts where input was captured per unit instead of per line total';

    public function handle(): int
    {
        $saleIds = collect((array) $this->option('sale-id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        $statuses = collect(explode(',', (string) $this->option('statuses')))
            ->map(fn ($s) => trim((string) $s))
            ->filter()
            ->values()
            ->all();

        $query = SaleItem::query()
            ->select('sale_items.*')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.discount_given', '>', 0)
            ->where('sale_items.quantity', '>', 1);

        if ($saleIds !== []) {
            $query->whereIn('sale_items.sale_id', $saleIds);
        }

        if ($statuses !== []) {
            $query->whereIn('sales.status', $statuses);
        }

        if ($from = $this->option('from-date')) {
            $query->whereDate('sales.created_at', '>=', (string) $from);
        }
        if ($to = $this->option('to-date')) {
            $query->whereDate('sales.created_at', '<=', (string) $to);
        }

        /** @var \Illuminate\Support\Collection<int, SaleItem> $rows */
        $rows = $query->get();
        if ($rows->isEmpty()) {
            $this->info('No candidate sale lines found for correction.');

            return self::SUCCESS;
        }

        $lineChanges = [];
        $saleAggregates = [];

        foreach ($rows as $row) {
            $qty = (float) $row->quantity;
            $oldDiscount = round((float) $row->discount_given, 2);
            $oldAmount = round((float) $row->amount, 2);
            $beforeDiscount = round($oldAmount + $oldDiscount, 2);
            $newDiscount = round($oldDiscount * $qty, 2);
            $newAmount = round(max(0, $beforeDiscount - $newDiscount), 2);
            $delta = round($newAmount - $oldAmount, 2);

            if (abs($delta) <= 0.009) {
                continue;
            }

            $lineChanges[] = [
                'id' => (int) $row->id,
                'sale_id' => (int) $row->sale_id,
                'old_discount' => $oldDiscount,
                'new_discount' => $newDiscount,
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
            ];

            $saleAggregates[(int) $row->sale_id] = true;
        }

        if ($lineChanges === []) {
            $this->info('Candidate lines found, but no effective value changes were needed.');

            return self::SUCCESS;
        }

        $this->info('Preview of line corrections:');
        foreach (array_slice($lineChanges, 0, 30) as $change) {
            $this->line(sprintf(
                '  sale #%d line #%d: discount %s -> %s, amount %s -> %s',
                $change['sale_id'],
                $change['id'],
                number_format($change['old_discount'], 2),
                number_format($change['new_discount'], 2),
                number_format($change['old_amount'], 2),
                number_format($change['new_amount'], 2),
            ));
        }
        if (count($lineChanges) > 30) {
            $this->line('  ...');
        }

        $this->line('Lines to update: '.count($lineChanges));
        $this->line('Sales to recalculate: '.count($saleAggregates));

        if ($this->option('dry-run')) {
            $this->info('Dry run complete — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Apply these discount corrections?', true)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($lineChanges): void {
            foreach ($lineChanges as $change) {
                SaleItem::query()
                    ->where('id', $change['id'])
                    ->update([
                        'discount_given' => $change['new_discount'],
                        'amount' => $change['new_amount'],
                    ]);
            }

            $saleIds = collect($lineChanges)->pluck('sale_id')->unique()->values();
            foreach ($saleIds as $saleId) {
                $sale = Sale::query()->with('items')->lockForUpdate()->find($saleId);
                if (! $sale) {
                    continue;
                }

                $orderTotal = round((float) $sale->items->sum('amount'), 2);
                $totalVat = round((float) $sale->items->sum('product_vat'), 2);
                $amountPaid = min((float) ($sale->amount_paid ?? 0), $orderTotal);

                $paymentStatus = $this->derivePaymentStatus($orderTotal, $amountPaid);

                $sale->update([
                    'order_total' => $orderTotal,
                    'total_vat' => $totalVat,
                    'amount_paid' => $amountPaid,
                    'payment_status' => $paymentStatus,
                ]);
            }
        });

        $this->info('Discount correction completed successfully.');

        return self::SUCCESS;
    }

    protected function derivePaymentStatus(float $total, float $paid): string
    {
        if ($total <= 0.01) {
            return 'paid';
        }
        if ($paid <= 0.01) {
            return 'unpaid';
        }
        if ($paid + 0.01 >= $total) {
            return 'paid';
        }

        return 'partial';
    }
}
