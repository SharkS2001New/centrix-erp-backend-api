<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('supplier_payments', 'lpo_supplier_invoice_id')) {
                $table->unsignedBigInteger('lpo_supplier_invoice_id')->nullable()->after('lpo_no');
                $table->index('lpo_supplier_invoice_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('supplier_payments', function (Blueprint $table) {
            if (Schema::hasColumn('supplier_payments', 'lpo_supplier_invoice_id')) {
                $table->dropIndex(['lpo_supplier_invoice_id']);
                $table->dropColumn('lpo_supplier_invoice_id');
            }
        });
    }
};
