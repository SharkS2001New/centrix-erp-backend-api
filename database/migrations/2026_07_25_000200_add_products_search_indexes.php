<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Speed up POS/catalog product search (org-scoped code equality + name prefix/sort).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect($sm->getIndexes('products'))->pluck('name')->all();

            if (! in_array('products_org_deleted_code_index', $indexes, true)) {
                $table->index(
                    ['organization_id', 'deleted_at', 'product_code'],
                    'products_org_deleted_code_index',
                );
            }

            if (! in_array('products_org_deleted_name_index', $indexes, true)
                && Schema::hasColumn('products', 'product_name')) {
                $table->index(
                    ['organization_id', 'deleted_at', 'product_name'],
                    'products_org_deleted_name_index',
                );
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $sm = Schema::getConnection()->getSchemaBuilder();
            $indexes = collect($sm->getIndexes('products'))->pluck('name')->all();

            if (in_array('products_org_deleted_code_index', $indexes, true)) {
                $table->dropIndex('products_org_deleted_code_index');
            }
            if (in_array('products_org_deleted_name_index', $indexes, true)) {
                $table->dropIndex('products_org_deleted_name_index');
            }
        });
    }
};
