<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('suppliers')) {
            return;
        }

        if (Schema::hasColumn('suppliers', 'terms_of_payment')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('terms_of_payment', 45)->nullable()->after('tax_pin');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('suppliers') || ! Schema::hasColumn('suppliers', 'terms_of_payment')) {
            return;
        }

        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn('terms_of_payment');
        });
    }
};
