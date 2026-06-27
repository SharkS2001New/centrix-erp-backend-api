<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('routes')) {
            return;
        }

        if (Schema::hasColumn('routes', 'receipt_payment_details')) {
            return;
        }

        Schema::table('routes', function (Blueprint $table) {
            $table->json('receipt_payment_details')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('routes') || ! Schema::hasColumn('routes', 'receipt_payment_details')) {
            return;
        }

        Schema::table('routes', function (Blueprint $table) {
            $table->dropColumn('receipt_payment_details');
        });
    }
};
