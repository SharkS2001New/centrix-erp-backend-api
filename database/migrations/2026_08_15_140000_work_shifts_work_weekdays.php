<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->json('work_weekdays')->nullable()->after('works_sunday');
        });

        $shifts = DB::table('work_shifts')->get(['id', 'works_saturday', 'works_sunday']);
        foreach ($shifts as $shift) {
            $days = [1, 2, 3, 4, 5];
            if ($shift->works_saturday) {
                $days[] = 6;
            }
            if ($shift->works_sunday) {
                $days[] = 0;
            }
            DB::table('work_shifts')->where('id', $shift->id)->update([
                'work_weekdays' => json_encode($days),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('work_shifts', function (Blueprint $table) {
            $table->dropColumn('work_weekdays');
        });
    }
};
