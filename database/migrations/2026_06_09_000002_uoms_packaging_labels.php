<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        Schema::table('uoms', function (Blueprint $table) {
            if (! Schema::hasColumn('uoms', 'measure_name')) {
                $table->string('measure_name', 120)->nullable()->after('full_name');
            }
            if (! Schema::hasColumn('uoms', 'small_packaging_label')) {
                $table->string('small_packaging_label', 45)->nullable()->after('measure_name');
            }
            if (! Schema::hasColumn('uoms', 'middle_packaging_label')) {
                $table->string('middle_packaging_label', 45)->nullable()->after('small_packaging_label');
            }
            if (! Schema::hasColumn('uoms', 'middle_factor')) {
                $table->float('middle_factor')->nullable()->after('middle_packaging_label');
            }
        });

        $this->backfillSmallLabels();
    }

    protected function backfillSmallLabels(): void
    {
        $defaults = [
            'bag' => 'kg',
            'carton' => 'piece',
            'bale' => 'piece',
            'box' => 'piece',
            'crate' => 'piece',
            'bundle' => 'piece',
            'dozen' => 'piece',
            'pack' => 'piece',
            'pallet' => 'piece',
            'roll' => 'piece',
            'sheet' => 'piece',
            'tonne' => 'kg',
            'kg' => 'kg',
            'g' => 'g',
            'l' => 'litres',
            'litre' => 'litres',
            'ml' => 'ml',
            'm' => 'm',
            'cm' => 'cm',
        ];

        $rows = \Illuminate\Support\Facades\DB::table('uoms')
            ->whereNull('small_packaging_label')
            ->where('conversion_factor', '>', 1)
            ->get(['id', 'uom_type']);

        foreach ($rows as $row) {
            $type = strtolower((string) $row->uom_type);
            $label = $defaults[$type] ?? null;
            if ($label) {
                \Illuminate\Support\Facades\DB::table('uoms')
                    ->where('id', $row->id)
                    ->update(['small_packaging_label' => $label]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('uoms')) {
            return;
        }

        Schema::table('uoms', function (Blueprint $table) {
            foreach (['middle_factor', 'middle_packaging_label', 'small_packaging_label', 'measure_name'] as $col) {
                if (Schema::hasColumn('uoms', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
