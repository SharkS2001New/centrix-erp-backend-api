<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_spend_links', function (Blueprint $table) {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('reference_label')->index();
            $table->unsignedBigInteger('lpo_no')->nullable()->after('supplier_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('investor_spend_links', function (Blueprint $table) {
            $table->dropIndex(['supplier_id']);
            $table->dropIndex(['lpo_no']);
            $table->dropColumn(['supplier_id', 'lpo_no']);
        });
    }
};
