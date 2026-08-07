<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL ngram FULLTEXT on product_name — substring-friendly name search
 * (MySQL equivalent of PostgreSQL pg_trgm) for remote product search when
 * the POS local catalog is not warmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'product_name')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $indexes = collect(Schema::getIndexes('products'))->pluck('name')->all();
        if (in_array('products_product_name_ngram', $indexes, true)) {
            return;
        }

        // ngram_token_size (default 2) enables mid-string matches like "unia" → "Gunia".
        DB::statement(
            'ALTER TABLE products ADD FULLTEXT INDEX products_product_name_ngram (product_name) WITH PARSER ngram'
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }

        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        $indexes = collect(Schema::getIndexes('products'))->pluck('name')->all();
        if (! in_array('products_product_name_ngram', $indexes, true)) {
            return;
        }

        DB::statement('ALTER TABLE products DROP INDEX products_product_name_ngram');
    }
};
