<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the user-entered transfer reason on stock_movement_history and expose
 * it on v_stock_transfers (one row per movement so reasons stay accurate).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        if (! Schema::hasColumn('stock_movement_history', 'notes')) {
            Schema::table('stock_movement_history', function (Blueprint $table) {
                $table->string('notes', 500)->nullable()->after('to_location');
            });
        }

        $this->backfillNotesFromLedger();
        $this->rebuildStockTransfersView();
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_movement_history')) {
            return;
        }

        // Restore previous aggregated view (without notes).
        DB::statement('DROP VIEW IF EXISTS v_stock_transfers');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_transfers AS
SELECT
    DATE(smh.created_at) AS transfer_date,
    smh.branch_id,
    b.organization_id,
    smh.product_code,
    MAX(p.product_name) AS product_name,
    smh.from_location,
    smh.to_location,
    SUM(smh.quantity_moved) AS total_moved,
    COUNT(*) AS transfer_count,
    MAX(uom.full_name) AS uom_name,
    MAX(uom.conversion_factor) AS conversion_factor,
    MAX(uom.small_packaging_label) AS small_packaging_label,
    MAX(uom.middle_packaging_label) AS middle_packaging_label,
    MAX(uom.middle_factor) AS middle_factor,
    MAX(uom.uom_type) AS uom_type
FROM stock_movement_history smh
JOIN branches b ON smh.branch_id = b.id
JOIN products p ON smh.product_code = p.product_code
    AND p.organization_id = b.organization_id
JOIN uoms uom ON uom.id = p.unit_id
GROUP BY DATE(smh.created_at), smh.branch_id, b.organization_id, smh.product_code, smh.from_location, smh.to_location
SQL);

        if (Schema::hasColumn('stock_movement_history', 'notes')) {
            Schema::table('stock_movement_history', function (Blueprint $table) {
                $table->dropColumn('notes');
            });
        }
    }

    protected function rebuildStockTransfersView(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_stock_transfers');
        DB::statement(<<<'SQL'
CREATE VIEW v_stock_transfers AS
SELECT
    smh.id AS movement_id,
    DATE(smh.created_at) AS transfer_date,
    smh.created_at AS transferred_at,
    smh.branch_id,
    b.organization_id,
    smh.product_code,
    p.product_name,
    smh.from_location,
    smh.to_location,
    smh.quantity_moved AS total_moved,
    1 AS transfer_count,
    smh.notes,
    uom.full_name AS uom_name,
    uom.conversion_factor AS conversion_factor,
    uom.small_packaging_label AS small_packaging_label,
    uom.middle_packaging_label AS middle_packaging_label,
    uom.middle_factor AS middle_factor,
    uom.uom_type AS uom_type
FROM stock_movement_history smh
JOIN branches b ON smh.branch_id = b.id
JOIN products p ON smh.product_code = p.product_code
    AND p.organization_id = b.organization_id
JOIN uoms uom ON uom.id = p.unit_id
SQL);
    }

    protected function backfillNotesFromLedger(): void
    {
        if (! Schema::hasTable('inventory_transactions')) {
            return;
        }

        $rows = DB::table('inventory_transactions')
            ->where('transaction_type', 'TRANSFER')
            ->where('quantity_change', '<', 0)
            ->where(function ($q) {
                $q->where('notes', 'like', 'Transfer out for %')
                    ->orWhere('notes', 'like', 'Transfer out to %');
            })
            ->orderBy('id')
            ->get(['product_code', 'branch_id', 'stock_location', 'quantity_change', 'created_by', 'notes', 'created_at']);

        foreach ($rows as $row) {
            $reason = $this->extractReason((string) ($row->notes ?? ''));
            if ($reason === null || $reason === '') {
                continue;
            }

            $qty = abs((float) $row->quantity_change);
            $from = (string) $row->stock_location;

            DB::table('stock_movement_history')
                ->where('product_code', $row->product_code)
                ->where('branch_id', $row->branch_id)
                ->where('from_location', $from)
                ->where('quantity_moved', $qty)
                ->where('moved_by', $row->created_by)
                ->whereDate('created_at', date('Y-m-d', strtotime((string) $row->created_at)))
                ->where(function ($q) {
                    $q->whereNull('notes')->orWhere('notes', '');
                })
                ->limit(1)
                ->update(['notes' => mb_substr($reason, 0, 500)]);
        }
    }

    protected function extractReason(string $notes): ?string
    {
        if (preg_match('/^Transfer out (?:for|to) .+?:\s*(.+)$/i', $notes, $m)) {
            return trim($m[1]);
        }

        return null;
    }
};
