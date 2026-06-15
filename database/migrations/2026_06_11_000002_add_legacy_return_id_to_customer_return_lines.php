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
            if (! Schema::hasColumn('customer_return_lines', 'legacy_return_id')) {
                $table->unsignedInteger('legacy_return_id')->nullable()->after('line_no');
                $table->index('legacy_return_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('customer_return_lines')) {
            return;
        }

        Schema::table('customer_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('customer_return_lines', 'legacy_return_id')) {
                $table->dropIndex(['legacy_return_id']);
                $table->dropColumn('legacy_return_id');
            }
        });
    }
};
