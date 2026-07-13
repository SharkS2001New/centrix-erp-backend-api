<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lpo_txn', function (Blueprint $table) {
            if (! Schema::hasColumn('lpo_txn', 'offer_qty')) {
                $table->double('offer_qty')->default(0)->after('received_qty');
            }
        });

        if (Schema::hasColumn('lpo_txn', 'offer_qty')) {
            DB::table('lpo_txn')->update([
                'offer_qty' => DB::raw('GREATEST(COALESCE(received_qty, 0) - COALESCE(ordered_qty, 0), 0)'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('lpo_txn', function (Blueprint $table) {
            if (Schema::hasColumn('lpo_txn', 'offer_qty')) {
                $table->dropColumn('offer_qty');
            }
        });
    }
};
