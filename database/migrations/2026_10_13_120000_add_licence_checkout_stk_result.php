<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_licence_checkouts')) {
            return;
        }

        Schema::table('platform_licence_checkouts', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_licence_checkouts', 'result_code')) {
                $table->integer('result_code')->nullable()->after('status');
            }
            if (! Schema::hasColumn('platform_licence_checkouts', 'result_desc')) {
                $table->string('result_desc', 255)->nullable()->after('result_code');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('platform_licence_checkouts')) {
            return;
        }

        Schema::table('platform_licence_checkouts', function (Blueprint $table) {
            if (Schema::hasColumn('platform_licence_checkouts', 'result_desc')) {
                $table->dropColumn('result_desc');
            }
            if (Schema::hasColumn('platform_licence_checkouts', 'result_code')) {
                $table->dropColumn('result_code');
            }
        });
    }
};
