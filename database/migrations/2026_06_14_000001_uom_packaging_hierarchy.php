<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->string('small_packaging_label', 45)->nullable()->after('full_name');
            $table->string('middle_packaging_label', 45)->nullable()->after('small_packaging_label');
            $table->float('middle_factor')->nullable()->after('middle_packaging_label');
        });

        $rows = DB::table('uoms')->get();
        foreach ($rows as $row) {
            $type = strtolower((string) ($row->uom_type ?? ''));
            $small = match (true) {
                in_array($type, ['kg', 'kilogram', 'g', 'gram'], true) => 'kg',
                in_array($type, ['l', 'litre', 'liter', 'ml'], true) => 'litres',
                in_array($type, ['piece', 'pcs', 'unit', 'count'], true) => 'pcs',
                default => $type !== '' ? $type : 'pcs',
            };

            DB::table('uoms')->where('id', $row->id)->update([
                'small_packaging_label' => $small,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->dropColumn(['small_packaging_label', 'middle_packaging_label', 'middle_factor']);
        });
    }
};
