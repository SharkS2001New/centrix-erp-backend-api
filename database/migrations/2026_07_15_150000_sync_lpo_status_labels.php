<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Align lpo_statuses with workflow codes 0–7 and backfill header status
 * for LPOs that already have received line qty (receive previously only
 * updated lpo_txn, so the list Status column stayed blank / stale).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('lpo_statuses')) {
            $rows = [
                0 => 'Awaiting check',
                1 => 'Awaiting approval',
                2 => 'Awaiting send',
                3 => 'Awaiting receive',
                4 => 'Partially received',
                5 => 'Fully received',
                6 => 'Cleared',
                7 => 'Cancelled / returned',
            ];

            foreach ($rows as $code => $name) {
                DB::table('lpo_statuses')->updateOrInsert(
                    ['status_code' => $code],
                    ['status_name' => $name],
                );
            }
        }

        if (! Schema::hasTable('lpo_mst') || ! Schema::hasTable('lpo_txn')) {
            return;
        }

        // Paid / cleared headers: move legacy code 5 → 6 when flagged cleared.
        DB::table('lpo_mst')
            ->where('cleared_flag', 1)
            ->where('lpo_status_code', 5)
            ->update(['lpo_status_code' => 6]);

        $candidates = DB::table('lpo_mst')
            ->whereNull('deleted_at')
            ->where(function ($q) {
                $q->whereNull('cleared_flag')->orWhere('cleared_flag', '!=', 1);
            })
            ->where('lpo_status_code', '<', 6)
            ->where('lpo_status_code', '!=', 7)
            ->pluck('lpo_no');

        foreach ($candidates as $lpoNo) {
            $lines = DB::table('lpo_txn')->where('lpo_no', $lpoNo)->get([
                'ordered_qty',
                'received_qty',
                'offer_qty',
            ]);
            if ($lines->isEmpty()) {
                continue;
            }

            $anyReceived = false;
            $allComplete = true;
            foreach ($lines as $txn) {
                $ordered = (float) ($txn->ordered_qty ?? 0);
                $received = (float) ($txn->received_qty ?? 0);
                $offer = (float) ($txn->offer_qty ?? max(0.0, $received - $ordered));
                $paidReceived = max(0.0, $received - $offer);
                if ($received > 0.0001) {
                    $anyReceived = true;
                }
                if ($ordered > 0.0001 && $paidReceived + 0.0001 < $ordered) {
                    $allComplete = false;
                }
            }

            if (! $anyReceived) {
                continue;
            }

            $next = $allComplete ? 5 : 4;
            DB::table('lpo_mst')
                ->where('lpo_no', $lpoNo)
                ->update(['lpo_status_code' => $next]);
        }
    }

    public function down(): void
    {
        // Keep expanded rows — rolling back labels would re-break the list.
    }
};
