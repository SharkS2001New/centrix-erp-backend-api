<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_return_documents', function (Blueprint $table) {
            $table->string('supplier_invoice_no', 120)->nullable()->after('lpo_no');
            $table->enum('reason_scope', ['order', 'per_product'])->default('order')->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_return_documents', function (Blueprint $table) {
            $table->dropColumn(['supplier_invoice_no', 'reason_scope']);
        });
    }
};
