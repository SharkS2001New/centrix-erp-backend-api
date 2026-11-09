<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('platform_licence_checkouts')
            && Schema::hasColumn('platform_licence_checkouts', 'mpesa_receipt')
        ) {
            Schema::table('platform_licence_checkouts', function (Blueprint $table) {
                $table->string('mpesa_receipt', 80)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('platform_licence_checkouts')
            && Schema::hasColumn('platform_licence_checkouts', 'mpesa_receipt')
        ) {
            Schema::table('platform_licence_checkouts', function (Blueprint $table) {
                $table->string('mpesa_receipt', 40)->nullable()->change();
            });
        }
    }
};
