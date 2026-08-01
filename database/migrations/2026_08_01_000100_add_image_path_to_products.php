<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('products')) {
            return;
        }
        if (! Schema::hasColumn('products', 'image_path')) {
            Schema::table('products', function (Blueprint $table) {
                $table->string('image_path', 500)->nullable()->after('shelf_location');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('products') && Schema::hasColumn('products', 'image_path')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('image_path');
            });
        }
    }
};
