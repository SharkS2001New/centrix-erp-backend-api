<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('sale_payments', 'float_session_id')) {
                $table->bigInteger('float_session_id')->nullable()->after('sale_id');
                $table->index('float_session_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            if (Schema::hasColumn('sale_payments', 'float_session_id')) {
                $table->dropIndex(['float_session_id']);
                $table->dropColumn('float_session_id');
            }
        });
    }
};
