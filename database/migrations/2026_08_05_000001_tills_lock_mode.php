<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('tills', 'lock_mode')) {
            return;
        }

        Schema::table('tills', function (Blueprint $table) {
            $table->string('lock_mode', 20)->nullable()->after('cashier_id');
        });
    }

    public function down(): void
    {
        Schema::table('tills', function (Blueprint $table) {
            $table->dropColumn('lock_mode');
        });
    }
};
