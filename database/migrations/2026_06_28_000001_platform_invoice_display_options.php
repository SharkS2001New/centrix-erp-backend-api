<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_invoices') && ! Schema::hasColumn('platform_invoices', 'invoice_options')) {
            Schema::table('platform_invoices', function (Blueprint $table) {
                $table->json('invoice_options')->nullable()->after('seller');
            });
        }

        if (Schema::hasTable('platform_invoice_saved_templates') && ! Schema::hasColumn('platform_invoice_saved_templates', 'invoice_options')) {
            Schema::table('platform_invoice_saved_templates', function (Blueprint $table) {
                $table->json('invoice_options')->nullable()->after('template_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('platform_invoices', 'invoice_options')) {
            Schema::table('platform_invoices', function (Blueprint $table) {
                $table->dropColumn('invoice_options');
            });
        }

        if (Schema::hasColumn('platform_invoice_saved_templates', 'invoice_options')) {
            Schema::table('platform_invoice_saved_templates', function (Blueprint $table) {
                $table->dropColumn('invoice_options');
            });
        }
    }
};
