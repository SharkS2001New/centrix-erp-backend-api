<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_invoices')
            || Schema::hasColumn('customer_invoices', 'organization_id')) {
            return;
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->unsignedInteger('organization_id')->nullable()->after('branch_id');
        });

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'organization_id')) {
            DB::statement('
                UPDATE customer_invoices ci
                INNER JOIN sales s ON s.id = ci.sale_id
                SET ci.organization_id = s.organization_id
                WHERE ci.organization_id IS NULL
            ');
        }

        if (Schema::hasTable('branches')) {
            DB::statement('
                UPDATE customer_invoices ci
                INNER JOIN branches b ON b.id = ci.branch_id
                SET ci.organization_id = b.organization_id
                WHERE ci.organization_id IS NULL
            ');
        }

        DB::statement('ALTER TABLE customer_invoices MODIFY organization_id INT UNSIGNED NOT NULL');

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->foreign('organization_id')->references('id')->on('organizations');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_invoices')
            || ! Schema::hasColumn('customer_invoices', 'organization_id')) {
            return;
        }

        Schema::table('customer_invoices', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropColumn('organization_id');
        });
    }
};
