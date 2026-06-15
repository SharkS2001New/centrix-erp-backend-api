<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_return_lines')) {
            return;
        }

        Schema::table('customer_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('customer_return_lines', 'uom')) {
                $table->string('uom', 45)->nullable()->after('product_name');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_return_lines')) {
            return;
        }

        Schema::table('customer_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('customer_return_lines', 'uom')) {
                $table->dropColumn('uom');
            }
        });
    }
};
