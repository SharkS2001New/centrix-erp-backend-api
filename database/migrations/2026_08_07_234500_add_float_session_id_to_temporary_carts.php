<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the open till float session on POS carts so Cash Sales # (pos_order_num)
 * stays scoped to the session after restore-to-cart / cart reuse.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('temporary_carts')) {
            return;
        }

        if (! Schema::hasColumn('temporary_carts', 'float_session_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->unsignedBigInteger('float_session_id')->nullable()->after('till_id');
                $table->index('float_session_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('temporary_carts')) {
            return;
        }

        if (Schema::hasColumn('temporary_carts', 'float_session_id')) {
            Schema::table('temporary_carts', function (Blueprint $table) {
                $table->dropIndex(['float_session_id']);
                $table->dropColumn('float_session_id');
            });
        }
    }
};
