<?php

namespace App\Console\Commands;

use App\Support\SalePaymentStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-shot repair: rewrite sales.payment_status from amount_paid vs order_total.
 * Safe to re-run. Use after deploying amount-based All Orders / X-Z-EOD fixes.
 */
class RepairSalePaymentStatusCommand extends Command
{
    protected $signature = 'sales:repair-payment-status
                            {--organization= : Limit to one organization_id}
                            {--dry-run : Report mismatches without updating}';

    protected $description = 'Align sales.payment_status labels with amount_paid vs order_total';

    public function handle(): int
    {
        $orgId = $this->option('organization');
        $dryRun = (bool) $this->option('dry-run');

        $query = DB::table('sales')->select([
            'id',
            'status',
            'order_total',
            'amount_paid',
            'payment_status',
            'organization_id',
        ]);
        if ($orgId !== null && $orgId !== '') {
            $query->where('organization_id', (int) $orgId);
        }

        $scanned = 0;
        $mismatched = 0;
        $updated = 0;

        $query->orderBy('id')->chunkById(500, function ($rows) use ($dryRun, &$scanned, &$mismatched, &$updated) {
            foreach ($rows as $row) {
                $scanned++;
                $expected = SalePaymentStatus::resolve(
                    (string) ($row->status ?? ''),
                    (float) ($row->order_total ?? 0),
                    (float) ($row->amount_paid ?? 0),
                );
                $current = SalePaymentStatus::normalizeLabel($row->payment_status ?? null);
                if ($current === $expected) {
                    continue;
                }
                $mismatched++;
                if ($dryRun) {
                    continue;
                }
                DB::table('sales')->where('id', $row->id)->update([
                    'payment_status' => $expected,
                ]);
                $updated++;
            }
        });

        $this->info(($dryRun ? '[dry-run] ' : '')."Scanned {$scanned}, mismatched {$mismatched}"
            .($dryRun ? '' : ", updated {$updated}").'.');

        return self::SUCCESS;
    }
}
