<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purpose destinations (donations, internal use, etc.) were never written to
 * stock_movement_history because to_location was ENUM(shop,store) only.
 * Expand the column and backfill past outbound purpose transfers from the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        DB::statement("ALTER TABLE stock_movement_history MODIFY COLUMN to_location ENUM(
            'shop',
            'store',
            'internal_use',
            'donations',
            'staff_consumption',
            'charity',
            'sample',
            'production',
            'display'
        ) NOT NULL");

        if (! Schema::hasTable('inventory_transactions')) {
            return;
        }

        $labelToPurpose = [
            'internal use' => 'internal_use',
            'donations' => 'donations',
            'staff consumption' => 'staff_consumption',
            'charity' => 'charity',
            'sample / demo' => 'sample',
            'production / manufacturing' => 'production',
            'display / merchandising' => 'display',
        ];

        $rows = DB::table('inventory_transactions')
            ->where('transaction_type', 'TRANSFER')
            ->where('quantity_change', '<', 0)
            ->where('notes', 'like', 'Transfer out for %')
            ->orderBy('id')
            ->get(['id', 'product_code', 'branch_id', 'stock_location', 'quantity_change', 'created_by', 'notes', 'created_at']);

        foreach ($rows as $row) {
            $notes = (string) ($row->notes ?? '');
            if (! preg_match('/^Transfer out for (.+?)(?::|$)/i', $notes, $m)) {
                continue;
            }
            $label = strtolower(trim($m[1]));
            $purpose = $labelToPurpose[$label] ?? null;
            if ($purpose === null) {
                continue;
            }

            $qty = abs((float) $row->quantity_change);
            $from = (string) $row->stock_location;
            if (! in_array($from, ['shop', 'store'], true) || $qty <= 0) {
                continue;
            }

            $exists = DB::table('stock_movement_history')
                ->where('product_code', $row->product_code)
                ->where('branch_id', $row->branch_id)
                ->where('from_location', $from)
                ->where('to_location', $purpose)
                ->where('quantity_moved', $qty)
                ->where('moved_by', $row->created_by)
                ->whereDate('created_at', date('Y-m-d', strtotime((string) $row->created_at)))
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('stock_movement_history')->insert([
                'product_code' => $row->product_code,
                'branch_id' => $row->branch_id,
                'quantity_moved' => $qty,
                'from_location' => $from,
                'to_location' => $purpose,
                'moved_by' => $row->created_by,
                'move_status' => 0,
                'created_at' => $row->created_at,
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        DB::table('stock_movement_history')
            ->whereNotIn('to_location', ['shop', 'store'])
            ->delete();

        DB::statement("ALTER TABLE stock_movement_history MODIFY COLUMN to_location ENUM('shop','store') NOT NULL");
    }
};
