<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_returns')) {
            return;
        }

        Schema::table('customer_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_returns', 'return_kind')) {
                $table->string('return_kind', 20)->default('standard')->after('status');
                $table->string('kra_original_invoice_number', 64)->nullable()->after('return_kind');
                $table->index(['organization_id', 'return_kind'], 'customer_returns_org_kind_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_returns')) {
            return;
        }

        Schema::table('customer_returns', function (Blueprint $table) {
            if (Schema::hasColumn('customer_returns', 'return_kind')) {
                $table->dropIndex('customer_returns_org_kind_idx');
                $table->dropColumn(['return_kind', 'kra_original_invoice_number']);
            }
        });
    }
};
