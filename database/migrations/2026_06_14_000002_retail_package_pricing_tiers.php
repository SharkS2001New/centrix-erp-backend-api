<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retail_package_settings', function (Blueprint $table) {
            $table->json('pricing_tiers')->nullable()->after('product_code');
        });

        $rows = DB::table('retail_package_settings')->get();
        foreach ($rows as $row) {
            $tiers = [];

            if ($row->max_qty_measure > 0) {
                $tiers[] = [
                    'min_qty' => 1,
                    'max_qty' => (float) $row->max_qty_measure,
                    'measure_level' => 'small',
                    'markup_price' => (float) ($row->markup_price ?? 0),
                ];
            }

            if ($row->wholesale_qty_measure > 0) {
                $tiers[] = [
                    'min_qty' => (float) ($row->max_qty_measure ?? 0) + 0.001,
                    'max_qty' => (float) $row->wholesale_qty_measure,
                    'measure_level' => 'middle',
                    'markup_price' => (float) ($row->wholesale_markup_price ?? 0),
                ];
            }

            if (count($tiers) > 0) {
                DB::table('retail_package_settings')->where('id', $row->id)->update([
                    'pricing_tiers' => json_encode($tiers),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('retail_package_settings', function (Blueprint $table) {
            $table->dropColumn('pricing_tiers');
        });
    }
};
