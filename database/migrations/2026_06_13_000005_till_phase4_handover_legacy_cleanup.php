<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('till_float_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('till_float_sessions', 'handed_over_from')) {
                $table->unsignedBigInteger('handed_over_from')->nullable()->after('cashier_id');
            }
            if (! Schema::hasColumn('till_float_sessions', 'handed_over_at')) {
                $table->timestamp('handed_over_at')->nullable()->after('handed_over_from');
            }
            if (! Schema::hasColumn('till_float_sessions', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable()->after('opened_at');
            }
        });

        Schema::table('tills', function (Blueprint $table) {
            if (Schema::hasColumn('tills', 'working_amount')) {
                $table->dropColumn('working_amount');
            }
            if (Schema::hasColumn('tills', 'float_breakdown')) {
                $table->dropColumn('float_breakdown');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            if (! Schema::hasColumn('tills', 'working_amount')) {
                $table->integer('working_amount')->default(0)->after('cashier_id');
            }
            if (! Schema::hasColumn('tills', 'float_breakdown')) {
                $table->json('float_breakdown')->nullable()->after('working_amount');
            }
        });

        Schema::table('till_float_sessions', function (Blueprint $table) {
            $columns = ['handed_over_from', 'handed_over_at', 'suspended_at'];
            foreach ($columns as $column) {
                if (Schema::hasColumn('till_float_sessions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
