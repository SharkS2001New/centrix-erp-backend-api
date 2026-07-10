<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('platform_subscriptions', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('contract_id');
                $table->foreign('invoice_id')
                    ->references('id')
                    ->on('platform_invoices')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('platform_subscriptions', function (Blueprint $table) {
            if (Schema::hasColumn('platform_subscriptions', 'invoice_id')) {
                $table->dropForeign(['invoice_id']);
                $table->dropColumn('invoice_id');
            }
        });
    }
};
