<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'branch_id')) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('organization_id');
                $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
                $table->index(['organization_id', 'branch_id'], 'products_org_branch_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'branch_id')) {
                $table->dropForeign(['branch_id']);
                $table->dropIndex('products_org_branch_idx');
                $table->dropColumn('branch_id');
            }
        });
    }
};
